<?php
/**
 * MMI_DB — Custom database abstraction (bundled/vendored copy).
 *
 * Identical behavior to the original mmi-hub/includes/class-mmi-db.php this was
 * vendored from (ADR-0007, superseding ADR-0002's mmi-hub-only placement) — same
 * wp_mmi_vip_* tables, same schema/migration logic. Only ever loaded once per
 * request, by whichever plugin's bundled copy wins version negotiation in
 * bootstrap.php — see ADR-0006. Do not hand-edit this file in a single plugin;
 * edit the canonical source (mmi-admin/lib/mmi-shared/) and re-sync
 * (sync-to-plugins.sh) to every plugin that bundles it.
 *
 * One behavioral addition over the mmi-hub original: lazy self-healing table
 * creation (see ensure_tables() below) — mmi-hub's own installer only ever
 * called maybe_create_tables() once, from its own activation hook, which a
 * genuinely standalone site (mmi-hub never installed) would never run.
 *
 * Replaces wp_options and WP transients for all mmi-vip data.
 * All storage is in dedicated custom tables prefixed with {wpdb->prefix}mmi_vip_*.
 *
 * Active tables (Step-D consolidation — 5 tables):
 *   mmi_vip_settings        — generic key/value settings (replaces wp_options for scalar mmi_ keys;
 *                             also stores primary-key config and csv-mapping blobs after Step D)
 *   mmi_vip_profiles        — import profiles list (field_mappings LONGTEXT column added in Step D)
 *   mmi_vip_import_history  — import run history records
 *   mmi_vip_job_state       — transient-like short-lived job state & locks
 *   mmi_vip_tax_mappings    — per-supplier taxonomy term mappings
 *
 * Retired tables (data migrated; no longer created on fresh installs):
 *   mmi_vip_field_mappings  — folded into mmi_vip_profiles.field_mappings
 *   mmi_vip_primary_keys    — folded into mmi_vip_settings (key: mmi_vip_primary_key_{id}_{type})
 *   mmi_vip_activities      — methods now log to MMI_Logger / read from import_history
 *   mmi_vip_csv_mappings    — folded into mmi_vip_settings (key: mmi_vip_csv_mappings_{id})
 *   mmi_vip_import_field_stats — no-op (field-level stats not stored)
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_DB {

	/**
	 * Per-request guard so the (idempotent, but not free — several dbDelta
	 * calls plus INFORMATION_SCHEMA lookups) maybe_create_tables() only
	 * actually runs its checks once per request, no matter how many of the
	 * 441+ MMI_DB:: call sites across the suite run in a single request.
	 */
	private static bool $tables_ensured = false;

	/**
	 * Lazy self-healing entry point — called from the top of every
	 * *_table() helper below so any code path that resolves a table name
	 * first guarantees that table (and every other active table) exists.
	 * Idempotent: maybe_create_tables() itself uses CREATE TABLE IF NOT
	 * EXISTS / INFORMATION_SCHEMA-guarded ALTERs, so calling it on a site
	 * that already has every table (i.e. mmi-hub is present and already
	 * ran its own activation hook) is cheap after the very first call this
	 * request, and correct either way.
	 */
	private static function ensure_tables(): void {
		if ( self::$tables_ensured ) {
			return;
		}
		self::$tables_ensured = true;
		self::maybe_create_tables();
	}

	// ---------------------------------------------------------------------------
	// Table name helpers
	// ---------------------------------------------------------------------------

	public static function settings_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_settings';
	}

	public static function field_mappings_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_field_mappings';
	}

	public static function profiles_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_profiles';
	}

	public static function primary_keys_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_primary_keys';
	}

	public static function import_history_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_import_history';
	}

	public static function job_state_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_job_state';
	}

	public static function activities_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_activities';
	}

	public static function csv_mappings_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_csv_mappings';
	}

	public static function tax_mappings_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_tax_mappings';
	}

	public static function import_field_stats_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_import_field_stats';
	}

	/**
	 * Per-product detail rows behind the Import Results "Field changes" table's
	 * per-field counts (see ProductImportController::process_import_batch_cron()'s
	 * $merged_field_stats / $top_changes) — a distinct table from the retired
	 * mmi_vip_import_field_stats above, which only ever stored aggregate counts.
	 * This one exists so an admin can expand a field-change row and spot-check
	 * exactly which products it affected, like a filtered log view.
	 */
	public static function import_field_change_items_table(): string {
		global $wpdb;
		self::ensure_tables();
		return $wpdb->prefix . 'mmi_vip_import_field_change_items';
	}

	// ---------------------------------------------------------------------------
	// Table creation & migration
	// ---------------------------------------------------------------------------

	/**
	 * Create all mmi_vip_* tables if they do not exist.
	 * Safe to call on every request (uses CREATE TABLE IF NOT EXISTS).
	 */
	public static function maybe_create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$settings_table = self::settings_table();
		dbDelta( "CREATE TABLE IF NOT EXISTS $settings_table (
			setting_key   VARCHAR(255) NOT NULL,
			setting_value LONGTEXT,
			updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (setting_key)
		) $charset_collate;" );

		// Retired tables — field_mappings_table is no longer created on fresh installs.
		// Existing installs retain the table (migration reads from it during transition).

		$profiles_table = self::profiles_table();
		dbDelta( "CREATE TABLE IF NOT EXISTS $profiles_table (
			profile_id          VARCHAR(100) NOT NULL,
			profile_name        VARCHAR(255) NOT NULL,
			description         TEXT,
			import_mode         VARCHAR(50)  NOT NULL DEFAULT 'update-only',
			mode_settings       LONGTEXT,
			product_scope       VARCHAR(20)  NOT NULL DEFAULT 'all_products',
			product_identifier  LONGTEXT,
			sources             LONGTEXT,
			field_mappings      LONGTEXT     DEFAULT NULL,
			data_type           VARCHAR(60)  NOT NULL DEFAULT 'product',
			direction            VARCHAR(10)  NOT NULL DEFAULT 'import',
			created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (profile_id)
		) $charset_collate;" );

		// Migration: add columns that were introduced after the initial table creation.
		// dbDelta does not ALTER existing tables, so we handle it explicitly.
		// Entries are ordered so that dependant columns appear after their neighbours.
		foreach ( [
			"import_mode         VARCHAR(50)  NOT NULL DEFAULT 'update-only'",
			"mode_settings       LONGTEXT",
			"product_scope       VARCHAR(20)  NOT NULL DEFAULT 'all_products'",
			"product_identifier  LONGTEXT",
			"sources             LONGTEXT",
			"field_mappings      LONGTEXT     DEFAULT NULL",
			"data_type           VARCHAR(60)  NOT NULL DEFAULT 'product'",
			"direction           VARCHAR(10)  NOT NULL DEFAULT 'import'",
		] as $col_def ) {
			$col_name = strtok( trim( $col_def ), ' ' );
			$exists   = $wpdb->get_results( $wpdb->prepare(
				"SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
				DB_NAME, $profiles_table, $col_name
			) );
			if ( empty( $exists ) ) {
				$wpdb->query( "ALTER TABLE $profiles_table ADD COLUMN $col_def" ); // phpcs:ignore WordPress.DB.PreparedSQL
			}
		}

		// Index for direction-filtered profile listing (Import tab vs. Export tab).
		// dbDelta does not reliably add secondary indexes to existing tables either,
		// so this uses the same INFORMATION_SCHEMA-existence-check pattern as the
		// column migration above.
		$index_exists = $wpdb->get_results( $wpdb->prepare(
			"SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = %s",
			DB_NAME, $profiles_table, 'data_type_direction'
		) );
		if ( empty( $index_exists ) ) {
			$wpdb->query( "ALTER TABLE $profiles_table ADD KEY data_type_direction (data_type, direction)" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// Retired tables — primary_keys, activities, csv_mappings, import_field_stats
		// are no longer created on fresh installs. Data was migrated to vip_settings
		// and vip_profiles.field_mappings by migrate_legacy_tables() below.

		$history_table = self::import_history_table();
		dbDelta( "CREATE TABLE IF NOT EXISTS $history_table (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			supplier_id  VARCHAR(100)        NOT NULL DEFAULT '',
			profile_id   VARCHAR(100)        NOT NULL DEFAULT 'default',
			started_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			completed_at DATETIME            DEFAULT NULL,
			imported     INT(11)             NOT NULL DEFAULT 0,
			updated      INT(11)             NOT NULL DEFAULT 0,
			skipped      INT(11)             NOT NULL DEFAULT 0,
			errors       INT(11)             NOT NULL DEFAULT 0,
			status       VARCHAR(50)         NOT NULL DEFAULT 'completed',
			notes        TEXT,
			PRIMARY KEY (id),
			KEY supplier_id (supplier_id),
			KEY started_at (started_at)
		) $charset_collate;" );

		$job_table = self::job_state_table();
		dbDelta( "CREATE TABLE IF NOT EXISTS $job_table (
			state_key   VARCHAR(255) NOT NULL,
			state_value LONGTEXT,
			expires_at  DATETIME     DEFAULT NULL,
			created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (state_key)
		) $charset_collate;" );

		// source_value uses utf8mb4_bin (case- and accent-sensitive) deliberately,
		// unlike the table's other columns — supplier feeds are inconsistent about
		// casing (e.g. xchange ships both "Avid" and "AVID" as genuinely separate
		// brand-field values across different product subsets), and the scan/lookup
		// code in TaxonomyMappingController.php already treats them as distinct rows
		// via case-sensitive PHP string keys. With the table's default collation
		// (case-insensitive), saving a mapping for "Avid" silently collided with an
		// existing "AVID" row through the UNIQUE KEY match (which compares
		// case-insensitively) while leaving the stored text as "AVID" — so the
		// PHP-side lookup for "Avid" then missed on the very next page load, making
		// a save that visibly succeeded look unmapped again after reload. See
		// maybe_migrate_tax_mappings_collation() below for the existing-install fix.
		$tax_table = self::tax_mappings_table();
		dbDelta( "CREATE TABLE IF NOT EXISTS $tax_table (
			id           BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			supplier_id  VARCHAR(100)        NOT NULL DEFAULT '',
			source_field VARCHAR(100)        NOT NULL,
			source_value VARCHAR(500)        NOT NULL COLLATE utf8mb4_bin,
			wc_taxonomy  VARCHAR(100)        NOT NULL,
			wc_term_id   BIGINT(20)          NOT NULL DEFAULT 0,
			auto_create  TINYINT(1)          NOT NULL DEFAULT 0,
			updated_at   DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY src_map (supplier_id, source_field, source_value(255), wc_taxonomy),
			KEY wc_taxonomy (wc_taxonomy),
			KEY supplier_id (supplier_id)
		) $charset_collate;" );
		self::maybe_migrate_tax_mappings_collation( $tax_table );

		// Per-product detail behind the Import Results "Field changes" table —
		// see import_field_change_items_table()'s own docblock. Detail rows per
		// (run, supplier, field) are capped at write time (see
		// ProductImportController's MAX_FIELD_CHANGE_ITEMS) — the aggregate
		// counts remain exact regardless, only this detail list is bounded,
		// same convention as MAX_FAILURE_DETAILS elsewhere in this plugin.
		$field_change_items_table = self::import_field_change_items_table();
		dbDelta( "CREATE TABLE IF NOT EXISTS $field_change_items_table (
			id            BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id        BIGINT(20) UNSIGNED NOT NULL,
			supplier_id   VARCHAR(100)        NOT NULL DEFAULT '',
			field_name    VARCHAR(100)        NOT NULL DEFAULT '',
			product_id    BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			sku           VARCHAR(255)        NOT NULL DEFAULT '',
			product_title VARCHAR(255)        NOT NULL DEFAULT '',
			old_value     VARCHAR(500)        NOT NULL DEFAULT '',
			new_value     VARCHAR(500)        NOT NULL DEFAULT '',
			created_at    DATETIME            NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY run_field (run_id, supplier_id, field_name),
			KEY created_at (created_at)
		) $charset_collate;" );

		// One-time migration: move data out of retired tables.
		self::migrate_legacy_tables();
	}

	/**
	 * Existing installs created source_value with the table's default (case-
	 * insensitive) collation — dbDelta never alters an existing column's
	 * definition, so the fresh-install fix above does nothing for sites that
	 * already have this table. Runs an explicit ALTER once, guarded by checking
	 * the column's actual current collation so it's a no-op on every later
	 * request (cheap INFORMATION_SCHEMA lookup, not a flag that could drift out
	 * of sync with reality).
	 */
	private static function maybe_migrate_tax_mappings_collation( string $table ): void {
		global $wpdb;

		$current = $wpdb->get_var( $wpdb->prepare(
			"SELECT COLLATION_NAME FROM INFORMATION_SCHEMA.COLUMNS
			 WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'source_value'",
			DB_NAME, $table
		) );

		if ( $current === null || $current === 'utf8mb4_bin' ) {
			return;
		}

		$wpdb->query( "ALTER TABLE $table MODIFY source_value VARCHAR(500) NOT NULL COLLATE utf8mb4_bin" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * One-time migration: copy existing mmi_ wp_options entries into our tables.
	 * Guarded by a setting flag — will not run twice.
	 */
	public static function migrate_from_wp_options(): void {
		if ( self::get_setting( 'mmi_db_migration_done' ) === '1' ) {
			return;
		}

		global $wpdb;

		// --- Settings: pull all mmi_ prefixed scalar options from wp_options ---
		$rows = $wpdb->get_results(
			"SELECT option_name, option_value FROM {$wpdb->options}
			 WHERE option_name LIKE 'mmi_%'
			   AND option_name NOT LIKE '_transient_%'",
			ARRAY_A
		);

		foreach ( $rows as $row ) {
			$key   = $row['option_name'];
			$value = $row['option_value'];

			// Field mappings — route to field_mappings table
			if ( $key === 'mmi_vip_field_mappings' ) {
				$mappings = maybe_unserialize( $value );
				if ( is_array( $mappings ) ) {
					self::set_field_mappings( 'default', $mappings );
				}
				continue;
			}
			if ( strpos( $key, 'mmi_vip_field_mappings_' ) === 0 ) {
				$profile_id = substr( $key, strlen( 'mmi_vip_field_mappings_' ) );
				$mappings   = maybe_unserialize( $value );
				if ( is_array( $mappings ) ) {
					self::set_field_mappings( $profile_id, $mappings );
				}
				continue;
			}

			// Import profiles — route to profiles table
			if ( $key === 'mmi_vip_import_profiles' ) {
				$profiles = maybe_unserialize( $value );
				if ( is_array( $profiles ) ) {
					self::set_profiles( $profiles );
				}
				continue;
			}

			// Primary keys — route to primary_keys table
			if ( preg_match( '/^mmi_vip_primary_key_(.+)_(source|wc)$/', $key, $m ) ) {
				self::set_primary_key( $m[1], $m[2], (string) $value );
				continue;
			}

			// Import history — route to import_history table (old format: serialized array)
			if ( $key === 'mmi_vip_import_history' ) {
				$history = maybe_unserialize( $value );
				if ( is_array( $history ) ) {
					foreach ( $history as $entry ) {
						if ( is_array( $entry ) ) {
							self::append_import_history( $entry );
						}
					}
				}
				continue;
			}

			// Recent activities — route to activities table
			if ( $key === 'mmi_recent_activities' ) {
				$activities = maybe_unserialize( $value );
				if ( is_array( $activities ) ) {
					foreach ( array_reverse( $activities ) as $act ) {
						$type    = $act['type'] ?? 'general';
						$message = $act['message'] ?? '';
						$context = $act;
						unset( $context['type'], $context['message'] );
						self::add_activity( $type, $message, $context );
					}
				}
				continue;
			}

			// CSV mappings
			if ( strpos( $key, 'mmi_vip_csv_mapping_' ) === 0 ) {
				$supplier = substr( $key, strlen( 'mmi_vip_csv_mapping_' ) );
				$mappings = maybe_unserialize( $value );
				if ( is_array( $mappings ) ) {
					self::set_csv_mappings( $supplier, $mappings );
				}
				continue;
			}

			// Everything else → settings table.
			// $value is the raw option_value from wp_options (may be serialized),
			// so unserialize first to avoid double-serialization via set_setting → maybe_serialize.
			self::set_setting( $key, maybe_unserialize( $value ) );
		}

		// Ensure at least one profile exists (fresh install only — use direct DB
		// count so we don't get tricked by any in-memory defaults).
		$profile_count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::profiles_table() );
		if ( $profile_count === 0 ) {
			self::set_profiles( [
				'full_import' => [
					'name'          => 'Full Product Import',
					'description'   => 'Complete product data including descriptions, images, and all metadata',
					'import_mode'   => 'create-and-update',
					'mode_settings' => [],
				],
			] );
		}

		// Mark migration as done
		self::set_setting( 'mmi_db_migration_done', '1' );

		MMI_Logger::info(  '[MMI_DB] Migration from wp_options completed.' , [], 'database', 'MMI_DB' );
	}

	// ---------------------------------------------------------------------------
	// Table consolidation migration (Step D)
	// ---------------------------------------------------------------------------

	/**
	 * One-time migration: move data from retired tables into consolidated storage.
	 *
	 * Runs automatically from maybe_create_tables(); guarded by a flag so it only
	 * ever executes once per installation.
	 *
	 * Migrated content:
	 *   mmi_vip_field_mappings  → mmi_vip_profiles.field_mappings (per profile_id)
	 *   mmi_vip_primary_keys    → mmi_vip_settings (key: mmi_vip_primary_key_{id}_{type})
	 *   mmi_vip_csv_mappings    → mmi_vip_settings (key: mmi_vip_csv_mappings_{id})
	 */
	public static function migrate_legacy_tables(): void {
		if ( self::get_setting( 'mmi_db_table_consolidation_done' ) === '1' ) {
			return;
		}

		global $wpdb;

		// ── 1. field_mappings → profiles.field_mappings ───────────────────────
		$fm_table = self::field_mappings_table();
		$profiles_table = self::profiles_table();

		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
			DB_NAME, $fm_table
		) ) ) {
			$rows = $wpdb->get_results( "SELECT profile_id, mappings FROM $fm_table", ARRAY_A );
			foreach ( (array) $rows as $row ) {
				// Only migrate if the profile exists and has no field_mappings yet.
				$current = $wpdb->get_var( $wpdb->prepare(
					"SELECT field_mappings FROM $profiles_table WHERE profile_id = %s",
					$row['profile_id']
				) );
				if ( $current === null ) {
					$wpdb->update(
						$profiles_table,
						[ 'field_mappings' => $row['mappings'] ],
						[ 'profile_id'     => $row['profile_id'] ],
						[ '%s' ],
						[ '%s' ]
					);
				}
			}
		}

		// ── 2. primary_keys → vip_settings ───────────────────────────────────
		$pk_table = self::primary_keys_table();

		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
			DB_NAME, $pk_table
		) ) ) {
			$rows = $wpdb->get_results(
				"SELECT supplier_id, key_type, key_value FROM $pk_table",
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$setting_key = "mmi_vip_primary_key_{$row['supplier_id']}_{$row['key_type']}";
				// Only write if the setting does not already exist in vip_settings.
				if ( self::get_setting( $setting_key ) === null ) {
					self::set_setting( $setting_key, $row['key_value'] );
				}
			}
		}

		// ── 3. csv_mappings → vip_settings ───────────────────────────────────
		$csv_table = self::csv_mappings_table();

		if ( $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
			DB_NAME, $csv_table
		) ) ) {
			$rows = $wpdb->get_results( "SELECT supplier_id, mappings FROM $csv_table", ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$setting_key = "mmi_vip_csv_mappings_{$row['supplier_id']}";
				if ( self::get_setting( $setting_key ) === null ) {
					self::set_setting( $setting_key, $row['mappings'] );
				}
			}
		}

		self::set_setting( 'mmi_db_table_consolidation_done', '1' );
		MMI_Logger::info( '[MMI_DB] Step-D table consolidation migration completed.', [], 'database', 'MMI_DB' );
	}

	// ---------------------------------------------------------------------------
	// Settings API  (replaces get_option / update_option / delete_option)
	// ---------------------------------------------------------------------------

	/**
	 * Get a setting value.
	 *
	 * @param  string $key     Setting key (any mmi_ prefixed option name).
	 * @param  mixed  $default Default value if not found.
	 * @return mixed
	 */
	public static function get_setting( string $key, $default = null ) {
		global $wpdb;

		$table = self::settings_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT setting_value FROM $table WHERE setting_key = %s", $key ),
			ARRAY_A
		);

		if ( $row === null ) {
			return $default;
		}

		$value = $row['setting_value'];

		// Attempt to unserialize — safe to call on plain strings
		$unserialized = @maybe_unserialize( $value );

		return $unserialized;
	}

	/**
	 * Set a setting value (insert or update).
	 *
	 * @param  string $key   Setting key.
	 * @param  mixed  $value Value. Arrays/objects are serialized automatically.
	 * @return bool
	 */
	public static function set_setting( string $key, $value ): bool {
		global $wpdb;

		$serialized = maybe_serialize( $value );
		$table      = self::settings_table();

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (setting_key, setting_value, updated_at)
				 VALUES (%s, %s, NOW())
				 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
				$key,
				$serialized
			)
		);

		return $result !== false;
	}

	/**
	 * Delete a setting.
	 *
	 * @param  string $key Setting key.
	 * @return bool
	 */
	public static function delete_setting( string $key ): bool {
		global $wpdb;

		$table  = self::settings_table();
		$result = $wpdb->delete( $table, [ 'setting_key' => $key ], [ '%s' ] );

		return $result !== false;
	}

	// ---------------------------------------------------------------------------
	// Field Mappings API  (Step D: reads/writes profiles.field_mappings column)
	// ---------------------------------------------------------------------------

	/**
	 * Get all field mappings for a profile.
	 *
	 * Reads from the field_mappings column on wp_mmi_vip_profiles.
	 * Falls back to the legacy wp_mmi_vip_field_mappings table if the profile row
	 * does not yet have a value (covers the window before migrate_legacy_tables runs).
	 *
	 * @param  string $profile_id Profile ID ('default' or custom).
	 * @return array
	 */
	public static function get_field_mappings( string $profile_id = 'default' ): array {
		global $wpdb;

		$profiles_table = self::profiles_table();
		$row            = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT field_mappings FROM $profiles_table WHERE profile_id = %s",
				$profile_id
			),
			ARRAY_A
		);

		if ( $row !== null && ! empty( $row['field_mappings'] ) ) {
			$decoded = json_decode( $row['field_mappings'], true );
			return is_array( $decoded ) ? $decoded : [];
		}

		// Fallback: check legacy table (exists on upgraded installs before migration runs).
		$legacy = self::field_mappings_table();
		$legacy_row = $wpdb->get_row(
			$wpdb->prepare( "SELECT mappings FROM $legacy WHERE profile_id = %s", $profile_id ),
			ARRAY_A
		);

		if ( $legacy_row ) {
			$decoded = json_decode( $legacy_row['mappings'], true );
			return is_array( $decoded ) ? $decoded : [];
		}

		return [];
	}

	/**
	 * Save all field mappings for a profile (full replace).
	 *
	 * Writes to the field_mappings column on wp_mmi_vip_profiles. Upserts
	 * rather than plain-UPDATEs: the wizard's Field Mapping/Attributes steps
	 * autosave while creating a brand-new profile, before that profile's row
	 * exists yet (set_profiles() only runs once, at the final "Create
	 * Profile" click) — a bare $wpdb->update() against a nonexistent
	 * profile_id matches 0 rows, and $wpdb->update() returns int 0 (not
	 * false) for "matched nothing," which the old `!== false` check here
	 * treated as success. Every field-mapping edit made while creating a
	 * profile was silently discarded: the browser showed "✓ Saved," but
	 * nothing was ever written, and the profile that finally got created a
	 * moment later fell back to the plugin's static defaults — the same
	 * defaults every other new profile falls back to, which is what made the
	 * loss look like "all profiles share one global config."
	 *
	 * Creating a placeholder row here (profile_name defaults to $profile_id)
	 * is safe: set_profiles()'s own INSERT ... ON DUPLICATE KEY UPDATE never
	 * lists field_mappings in its UPDATE clause, so when the real profile
	 * row is written moments later it hits the UPDATE branch (profile_id
	 * already exists) and leaves this column untouched rather than
	 * overwriting it back to empty.
	 *
	 * @param  string $profile_id Profile ID.
	 * @param  array  $mappings   Full mappings array.
	 * @return bool
	 */
	public static function set_field_mappings( string $profile_id, array $mappings ): bool {
		global $wpdb;

		$profiles_table = self::profiles_table();
		$result         = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $profiles_table (profile_id, profile_name, field_mappings, created_at, updated_at)
				 VALUES (%s, %s, %s, NOW(), NOW())
				 ON DUPLICATE KEY UPDATE field_mappings = VALUES(field_mappings), updated_at = NOW()",
				$profile_id,
				$profile_id,
				wp_json_encode( $mappings )
			)
		);

		return $result !== false;
	}

	/**
	 * Atomically read-modify-write the field_mappings blob for one profile.
	 *
	 * Every autosave call site (field-mapping enabled toggle, supplier-enabled
	 * toggle, generic field-property save, bulk group/master toggle) reads the
	 * full field_mappings blob, mutates a single field within it, then replaces
	 * the whole column. Two such requests firing close together — e.g. a user
	 * clicking two supplier checkboxes on the same field within the same
	 * second — can race: both read the same pre-mutation blob, and whichever
	 * writes last silently discards the other's change even though both AJAX
	 * calls reported success to the browser. A MySQL named lock scoped to the
	 * profile serializes the read-modify-write cycle across concurrent
	 * PHP-FPM processes; unlike a transient-based lock it is held by the DB
	 * connection itself and is automatically released if a process dies
	 * mid-request, so it can never strand a profile permanently locked.
	 *
	 * @param  string   $profile_id Profile ID.
	 * @param  callable $mutator    Receives the current mappings array, must
	 *                              return the mutated array to persist.
	 * @return bool True if the lock was acquired and the write succeeded.
	 */
	public static function update_field_mappings( string $profile_id, callable $mutator ): bool {
		global $wpdb;

		$lock_name = 'mmi_field_mappings_' . $profile_id;
		$acquired  = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock_name ) );
		if ( 1 !== $acquired ) {
			return false;
		}

		try {
			$mappings = self::get_field_mappings( $profile_id );
			$mappings = $mutator( $mappings );
			return self::set_field_mappings( $profile_id, $mappings );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Delete field mappings for a profile.
	 *
	 * @param  string $profile_id Profile ID.
	 * @return bool
	 */
	public static function delete_field_mappings( string $profile_id ): bool {
		global $wpdb;

		$profiles_table = self::profiles_table();
		$result         = $wpdb->update(
			$profiles_table,
			[ 'field_mappings' => null ],
			[ 'profile_id'     => $profile_id ],
			[ '%s' ],
			[ '%s' ]
		);

		return $result !== false;
	}

	// ---------------------------------------------------------------------------
	// Profiles API
	// ---------------------------------------------------------------------------

	/**
	 * Get the profiles array (keyed by profile_id).
	 *
	 * @return array<string, array{name: string, description: string}>
	 */
	public static function get_profiles(): array {
		global $wpdb;

		$table = self::profiles_table();
		$rows  = $wpdb->get_results( "SELECT * FROM $table ORDER BY profile_id ASC", ARRAY_A );

		$profiles = [];
		foreach ( $rows as $row ) {
			$mode_settings = [];
			if ( ! empty( $row['mode_settings'] ) ) {
				$decoded = json_decode( $row['mode_settings'], true );
				if ( is_array( $decoded ) ) {
					$mode_settings = $decoded;
				}
			}
			$product_identifier = null;
			if ( ! empty( $row['product_identifier'] ) ) {
				$decoded = json_decode( $row['product_identifier'], true );
				if ( is_array( $decoded ) ) {
					$product_identifier = $decoded;
				}
			}
			$sources = [];
			if ( ! empty( $row['sources'] ) ) {
				$decoded = json_decode( $row['sources'], true );
				if ( is_array( $decoded ) ) {
					$sources = $decoded;
				}
			}
			$profiles[ $row['profile_id'] ] = [
				'name'               => $row['profile_name'],
				'description'        => $row['description'] ?? '',
				'import_mode'        => $row['import_mode'] ?? 'update-only',
				'mode_settings'      => $mode_settings,
				'product_scope'      => $row['product_scope'] ?? 'all_products',
				'product_identifier' => $product_identifier,
				'sources'            => $sources,
				'data_type'          => $row['data_type'] ?? 'product',
				'direction'          => $row['direction'] ?? 'import',
			];
		}

		return $profiles;
	}

	/**
	 * Get profiles filtered to a single direction ('import' or 'export').
	 *
	 * Thin filter over get_profiles() — the profiles table is small (per-site
	 * scale, not per-row-of-data scale), so a dedicated indexed query is not
	 * needed to keep this cheap.
	 *
	 * @param  string $direction 'import' or 'export'.
	 * @return array<string, array>
	 */
	public static function get_profiles_by_direction( string $direction ): array {
		return array_filter(
			self::get_profiles(),
			static function ( $profile ) use ( $direction ) {
				return ( $profile['direction'] ?? 'import' ) === $direction;
			}
		);
	}

	/**
	 * Save the full profiles array (upsert each entry).
	 *
	 * @param  array $profiles Profiles array keyed by profile_id.
	 * @return bool
	 */
	public static function set_profiles( array $profiles ): bool {
		global $wpdb;

		$table  = self::profiles_table();
		$ok     = true;

		foreach ( $profiles as $profile_id => $data ) {
			$mode_settings_json       = wp_json_encode( $data['mode_settings'] ?? [] );
			$product_identifier_json  = isset( $data['product_identifier'] ) ? wp_json_encode( $data['product_identifier'] ) : null;
			$sources_json             = ! empty( $data['sources'] ) ? wp_json_encode( array_values( $data['sources'] ) ) : null;
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $table (profile_id, profile_name, description, import_mode, mode_settings, product_scope, product_identifier, sources, data_type, direction, created_at, updated_at)
					 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
					 ON DUPLICATE KEY UPDATE profile_name = VALUES(profile_name), description = VALUES(description), import_mode = VALUES(import_mode), mode_settings = VALUES(mode_settings), product_scope = VALUES(product_scope), product_identifier = VALUES(product_identifier), sources = VALUES(sources), data_type = VALUES(data_type), direction = VALUES(direction), updated_at = NOW()",
					$profile_id,
					$data['name'] ?? $profile_id,
					$data['description'] ?? '',
					$data['import_mode'] ?? 'update-only',
					$mode_settings_json,
					$data['product_scope'] ?? 'all_products',
					$product_identifier_json,
					$sources_json,
					$data['data_type'] ?? 'product',
					$data['direction'] ?? 'import'
				)
			);
			if ( $result === false ) {
				$ok = false;
			}
		}

		return $ok;
	}

	/**
	 * Upsert a single profile.
	 *
	 * @param  string $profile_id   Profile ID.
	 * @param  string $profile_name Display name.
	 * @param  string $description  Optional description.
	 * @param  string $data_type    Data type key ('product', 'order', 'cpt:{slug}', etc.).
	 * @param  string $direction    'import' or 'export'.
	 * @return bool
	 */
	public static function upsert_profile(
		string $profile_id,
		string $profile_name,
		string $description    = '',
		string $import_mode    = 'update-only',
		array  $mode_settings  = [],
		string $product_scope  = 'all_products',
		?array $product_identifier = null,
		array  $sources        = [],
		string $data_type      = 'product',
		string $direction      = 'import'
	): bool {
		global $wpdb;

		$table  = self::profiles_table();
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (profile_id, profile_name, description, import_mode, mode_settings, product_scope, product_identifier, sources, data_type, direction, created_at, updated_at)
				 VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW(), NOW())
				 ON DUPLICATE KEY UPDATE profile_name = VALUES(profile_name), description = VALUES(description), import_mode = VALUES(import_mode), mode_settings = VALUES(mode_settings), product_scope = VALUES(product_scope), product_identifier = VALUES(product_identifier), sources = VALUES(sources), data_type = VALUES(data_type), direction = VALUES(direction), updated_at = NOW()",
				$profile_id,
				$profile_name,
				$description,
				$import_mode,
				wp_json_encode( $mode_settings ),
				$product_scope,
				isset( $product_identifier ) ? wp_json_encode( $product_identifier ) : null,
				! empty( $sources ) ? wp_json_encode( array_values( $sources ) ) : null,
				$data_type,
				$direction
			)
		);

		return $result !== false;
	}

	/**
	 * Migrate existing profiles to have an explicit import_mode, derived from
	 * their legacy allow_create_products setting. Safe to call repeatedly.
	 */
	public static function migrate_profiles_to_modes(): void {
		global $wpdb;

		$table    = self::profiles_table();
		$profiles = $wpdb->get_results( "SELECT profile_id, import_mode FROM $table", ARRAY_A );

		foreach ( $profiles as $row ) {
			// Any non-empty import_mode means the profile was already explicitly configured — skip.
			// 'update-only' is a valid, explicitly set value; do NOT treat it as "needs migration".
			if ( ! empty( $row['import_mode'] ) ) {
				continue;
			}

			$profile_id = $row['profile_id'];

			// Read the legacy per-profile setting.
			$option_key    = $profile_id === 'default'
				? 'mmi_vip_import_allow_create_products'
				: 'mmi_vip_import_allow_create_products_' . $profile_id;
			$allow_create  = self::get_setting( $option_key, null );

			// Only override if the setting explicitly exists in the DB.
			if ( $allow_create === null ) {
				continue;
			}

			$mode = $allow_create ? 'create-and-update' : 'update-only';
			$wpdb->update( $table, [ 'import_mode' => $mode ], [ 'profile_id' => $profile_id ], [ '%s' ], [ '%s' ] );
		}
	}

	/**
	 * Delete a single profile and its field mappings.
	 *
	 * @param  string $profile_id Profile ID.
	 * @return bool
	 */
	public static function delete_profile( string $profile_id ): bool {
		global $wpdb;

		$table  = self::profiles_table();
		$result = $wpdb->delete( $table, [ 'profile_id' => $profile_id ], [ '%s' ] );
		self::delete_field_mappings( $profile_id );

		return $result !== false;
	}

	// ---------------------------------------------------------------------------
	// Primary Keys API  (Step D: reads/writes vip_settings)
	// ---------------------------------------------------------------------------

	/**
	 * Get a primary key value for a supplier.
	 *
	 * Reads from vip_settings (key: mmi_vip_primary_key_{supplier_id}_{key_type}).
	 * Falls back to the legacy wp_mmi_vip_primary_keys table during the transition
	 * window before migrate_legacy_tables() has run.
	 *
	 * @param  string $supplier_id Supplier ID.
	 * @param  string $key_type    'source' or 'wc'.
	 * @param  mixed  $default     Default if not found.
	 * @return string|mixed
	 */
	public static function get_primary_key( string $supplier_id, string $key_type, $default = '' ) {
		$setting_key = "mmi_vip_primary_key_{$supplier_id}_{$key_type}";
		$value       = self::get_setting( $setting_key );

		if ( $value !== null ) {
			return (string) $value;
		}

		// Fallback: legacy table (exists on upgraded installs before migration ran).
		global $wpdb;
		$legacy = self::primary_keys_table();
		$legacy_val = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT key_value FROM $legacy WHERE supplier_id = %s AND key_type = %s",
				$supplier_id,
				$key_type
			)
		);

		if ( $legacy_val !== null ) {
			return $legacy_val;
		}

		// Self-heal, 'source' only: a hard-wired API integration knows its own
		// feed schema at dev time (e.g. Xchange's identifying field is always
		// 'sku') — unlike the 'wc' match target, which is a genuine per-site
		// decision, there's nothing for a user to decide here. Mirrors
		// MMI_Xchange_API_Client::self_heal_assets_url()'s shape: derive the
		// known value and persist it via set_primary_key() so it becomes real
		// saved state every later reader sees directly, not a per-request
		// fallback recomputed on each call. mmi-hub stays supplier-agnostic —
		// the actual per-supplier defaults are supplied by whichever plugin
		// integrates that supplier, via this filter.
		if ( $key_type === 'source' ) {
			$known_default = apply_filters( 'mmi_primary_key_source_default', '', $supplier_id );
			if ( $known_default !== '' ) {
				self::set_primary_key( $supplier_id, 'source', $known_default );
				return $known_default;
			}
		}

		return $default;
	}

	/**
	 * Set a primary key for a supplier.
	 *
	 * Writes to vip_settings (key: mmi_vip_primary_key_{supplier_id}_{key_type}).
	 *
	 * @param  string $supplier_id Supplier ID.
	 * @param  string $key_type    'source' or 'wc'.
	 * @param  string $key_value   Value to save.
	 * @return bool
	 */
	public static function set_primary_key( string $supplier_id, string $key_type, string $key_value ): bool {
		$setting_key = "mmi_vip_primary_key_{$supplier_id}_{$key_type}";
		return self::set_setting( $setting_key, $key_value );
	}

	// ---------------------------------------------------------------------------
	// Import History API
	// ---------------------------------------------------------------------------

	/**
	 * Get import history entries, newest first.
	 *
	 * @param  int $limit Maximum number of records.
	 * @return array
	 */
	public static function get_import_history( int $limit = 100 ): array {
		global $wpdb;

		$table = self::import_history_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $table ORDER BY started_at DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Total count of import history rows — cheap, for display text ("Last N
	 * import runs") without needing to fetch every row just to count them.
	 *
	 * @return int
	 */
	public static function count_import_history(): int {
		global $wpdb;

		$table = self::import_history_table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	}

	/**
	 * Distinct profile_ids present anywhere in import history, for filter-pill
	 * rendering — kept independent of get_import_history()'s own $limit so a
	 * page rendering only a recent slice of history still shows a pill for
	 * every profile that has ANY history, not just the ones in that slice.
	 *
	 * @return string[]
	 */
	public static function get_import_history_profile_ids(): array {
		global $wpdb;

		$table = self::import_history_table();
		$ids   = $wpdb->get_col( "SELECT DISTINCT profile_id FROM $table" );

		return is_array( $ids ) ? $ids : [];
	}

	/**
	 * Append a single import history entry.
	 *
	 * Accepts both the new schema format and the legacy wp_options serialized array format.
	 *
	 * @param  array $entry Entry data.
	 * @return bool
	 */
	public static function append_import_history( array $entry ): int|false {
		global $wpdb;

		$table = self::import_history_table();

		$result = $wpdb->insert(
			$table,
			[
				'supplier_id'  => sanitize_text_field( $entry['supplier_id'] ?? $entry['supplier'] ?? '' ),
				'profile_id'   => sanitize_text_field( $entry['profile_id'] ?? 'default' ),
				'started_at'   => $entry['started_at'] ?? $entry['date'] ?? current_time( 'mysql' ),
				'completed_at' => $entry['completed_at'] ?? null,
				'imported'     => (int) ( $entry['imported'] ?? $entry['created'] ?? 0 ),
				'updated'      => (int) ( $entry['updated'] ?? 0 ),
				'skipped'      => (int) ( $entry['skipped'] ?? 0 ),
				'errors'       => (int) ( $entry['errors'] ?? $entry['failed'] ?? 0 ),
				'status'       => sanitize_text_field( $entry['status'] ?? 'completed' ),
				'notes'        => $entry['notes'] ?? null,
			],
			[ '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' ]
		);

		if ( $result === false ) {
			return false;
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an existing import history row by its primary key.
	 *
	 * @param  int   $id    Row ID returned by append_import_history().
	 * @param  array $entry Columns to update (same keys as append_import_history).
	 * @return bool
	 */
	public static function update_import_history( int $id, array $entry ): bool {
		global $wpdb;

		$data   = [];
		$format = [];

		$string_fields = [ 'supplier_id', 'profile_id', 'started_at', 'completed_at', 'status', 'notes' ];
		$int_fields    = [ 'imported', 'updated', 'skipped', 'errors' ];

		foreach ( $string_fields as $field ) {
			if ( array_key_exists( $field, $entry ) ) {
				$data[ $field ]   = $entry[ $field ];
				$format[]         = '%s';
			}
		}
		foreach ( $int_fields as $field ) {
			if ( array_key_exists( $field, $entry ) ) {
				$data[ $field ] = (int) $entry[ $field ];
				$format[]       = '%d';
			}
		}

		if ( empty( $data ) ) {
			return false;
		}

		$result = $wpdb->update(
			self::import_history_table(),
			$data,
			[ 'id' => $id ],
			$format,
			[ '%d' ]
		);

		return $result !== false;
	}

	// ---------------------------------------------------------------------------
	// Job State API  (replaces get_transient / set_transient / delete_transient)
	// ---------------------------------------------------------------------------

	/**
	 * Get a job state value.
	 * Returns null if the key does not exist or has expired.
	 *
	 * @param  string $key State key.
	 * @return mixed|null
	 */
	public static function get_job_state( string $key ) {
		global $wpdb;

		$table = self::job_state_table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT state_value, expires_at FROM $table WHERE state_key = %s",
				$key
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return null;
		}

		// Honour TTL — expires_at is stored in UTC (gmdate), so force UTC
		// interpretation in strtotime() to avoid the local-time mismatch that
		// makes a 1-hour lock appear valid for 6-7 hours on non-UTC servers.
		if ( $row['expires_at'] !== null && strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			// Expired — clean up and return null
			$wpdb->delete( $table, [ 'state_key' => $key ], [ '%s' ] );
			return null;
		}

		$value = @maybe_unserialize( $row['state_value'] );

		return $value;
	}

	/**
	 * Set a job state value.
	 *
	 * @param  string $key        State key.
	 * @param  mixed  $value      Value to store. Arrays are serialized.
	 * @param  int    $ttl        TTL in seconds (0 = no expiry).
	 * @return bool
	 */
	public static function set_job_state( string $key, $value, int $ttl = 0 ): bool {
		global $wpdb;

		$serialized = maybe_serialize( $value );
		$expires_at = $ttl > 0 ? gmdate( 'Y-m-d H:i:s', time() + $ttl ) : null;
		$table      = self::job_state_table();

		if ( $expires_at !== null ) {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $table (state_key, state_value, expires_at, created_at, updated_at)
					 VALUES (%s, %s, %s, NOW(), NOW())
					 ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), expires_at = VALUES(expires_at), updated_at = NOW()",
					$key,
					$serialized,
					$expires_at
				)
			);
		} else {
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO $table (state_key, state_value, expires_at, created_at, updated_at)
					 VALUES (%s, %s, NULL, NOW(), NOW())
					 ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), expires_at = NULL, updated_at = NOW()",
					$key,
					$serialized
				)
			);
		}

		return $result !== false;
	}

	/**
	 * Delete a job state entry.
	 *
	 * @param  string $key State key.
	 * @return bool
	 */
	public static function delete_job_state( string $key ): bool {
		global $wpdb;

		$table  = self::job_state_table();
		$result = $wpdb->delete( $table, [ 'state_key' => $key ], [ '%s' ] );

		return $result !== false;
	}

	/**
	 * Purge all expired job state entries (maintenance helper).
	 *
	 * @return int Number of rows deleted.
	 */
	public static function purge_expired_job_states(): int {
		global $wpdb;

		$table  = self::job_state_table();
		$result = $wpdb->query(
			"DELETE FROM $table WHERE expires_at IS NOT NULL AND expires_at < NOW()"
		);

		return (int) $result;
	}

	// ---------------------------------------------------------------------------
	// Recent Activities API  (Step D: redirected to import_history / MMI_Logger)
	// ---------------------------------------------------------------------------

	/**
	 * Get the most recent activity entries, newest first.
	 *
	 * Step D: the dedicated activities table is retired.  This method now reads
	 * from wp_mmi_vip_import_history and returns rows shaped to match the
	 * activity record format (activity_type, message, created_at, context) so
	 * existing callers (ProcessLogController, class-product-importer-ui) receive
	 * compatible data without any caller changes.
	 *
	 * @param  int $limit Maximum rows to return.
	 * @return array
	 */
	public static function get_recent_activities( int $limit = 20 ): array {
		$history = self::get_import_history( $limit );

		$activities = [];
		foreach ( $history as $row ) {
			$imported = (int) ( $row['imported'] ?? 0 );
			$updated  = (int) ( $row['updated']  ?? 0 );
			$skipped  = (int) ( $row['skipped']  ?? 0 );
			$errors   = (int) ( $row['errors']   ?? 0 );
			$supplier = $row['supplier_id'] ?? '';
			$status   = $row['status'] ?? 'completed';

			$parts = [];
			if ( $imported > 0 ) $parts[] = "Imported: {$imported}";
			if ( $updated  > 0 ) $parts[] = "Updated: {$updated}";
			if ( $skipped  > 0 ) $parts[] = "Skipped: {$skipped}";
			if ( $errors   > 0 ) $parts[] = "Errors: {$errors}";
			$summary = $parts ? implode( ', ', $parts ) : ucfirst( $status );
			if ( $supplier ) {
				$summary .= " ({$supplier})";
			}
			// A bare "Errors: N" tells a human nothing actionable — every writer of
			// this table's 'notes' column (abandoned-run detector, batch import
			// failures, the manual-import catch block) already captures the real
			// reason there; this just surfaces it instead of discarding it.
			if ( $errors > 0 ) {
				$detail = self::extract_error_detail( $row['notes'] ?? null );
				if ( $detail !== '' ) {
					$summary .= " — {$detail}";
				}
			}

			$errors > 0 ? $activity_type = 'import_error' : $activity_type = 'import';
			if ( $status === 'error' || $status === 'failed' ) {
				$activity_type = 'import_error';
			}

			$activities[] = [
				'id'            => $row['id'] ?? null,
				'activity_type' => $activity_type,
				'message'       => $summary,
				'context'       => [
					'supplier_id' => $supplier,
					'profile_id'  => $row['profile_id'] ?? 'default',
					'imported'    => $imported,
					'updated'     => $updated,
					'skipped'     => $skipped,
					'errors'      => $errors,
					'status'      => $status,
				],
				'created_at'    => $row['started_at'] ?? current_time( 'mysql' ),
			];
		}

		return $activities;
	}

	/**
	 * Pull a short, human-readable reason out of an import_history row's
	 * 'notes' JSON, for use by get_recent_activities(). Checks the shapes
	 * every current writer uses, in order of specificity:
	 *  - 'last_error'    — abandoned scheduled-run detector (MMI_Pipeline_Cron)
	 *  - 'failures'[0]   — per-item detail from a batch/manual import (key/supplier/reason)
	 *  - 'error'         — manual import's outer catch-all
	 *
	 * @param  string|null $notes_json Raw JSON from the import_history 'notes' column.
	 * @return string Empty when nothing usable is present.
	 */
	private static function extract_error_detail( ?string $notes_json ): string {
		if ( empty( $notes_json ) ) {
			return '';
		}

		$notes = json_decode( $notes_json, true );
		if ( ! is_array( $notes ) ) {
			return '';
		}

		if ( ! empty( $notes['last_error'] ) && is_string( $notes['last_error'] ) ) {
			return $notes['last_error'];
		}

		if ( ! empty( $notes['failures'][0] ) && is_array( $notes['failures'][0] ) ) {
			$f = $notes['failures'][0];
			return sprintf( '%s (%s): %s', $f['key'] ?? '?', $f['supplier'] ?? '?', $f['reason'] ?? 'unknown error' );
		}

		if ( ! empty( $notes['error'] ) && is_string( $notes['error'] ) ) {
			return $notes['error'];
		}

		return '';
	}

	/**
	 * Append an activity log entry.
	 *
	 * Step D: the activities table is retired. This method now routes the event
	 * to MMI_Logger so it still appears in log files without writing to a DB
	 * table. The return value is always true so callers behave identically.
	 *
	 * @param  string $type    Activity type (e.g. 'import', 'fetch', 'error').
	 * @param  string $message Human-readable message.
	 * @param  array  $context Additional JSON-encodable context.
	 * @return bool
	 */
	public static function add_activity( string $type, string $message, array $context = [] ): bool {
		$is_error = stripos( $type, 'error' ) !== false || stripos( $type, 'fail' ) !== false;

		if ( $is_error ) {
			MMI_Logger::error( "[Activity:{$type}] {$message}", $context, 'pipeline', 'MMI_DB' );
		} else {
			MMI_Logger::info( "[Activity:{$type}] {$message}", $context, 'pipeline', 'MMI_DB' );
		}

		return true;
	}

	// ---------------------------------------------------------------------------
	// CSV Mappings API  (Step D: reads/writes vip_settings)
	// ---------------------------------------------------------------------------

	/**
	 * Get CSV column mappings for a supplier.
	 *
	 * Step D: reads from vip_settings (key: mmi_vip_csv_mappings_{supplier_id}).
	 * Falls back to the legacy wp_mmi_vip_csv_mappings table during the migration
	 * window before migrate_legacy_tables() has run.
	 *
	 * @param  string $supplier_id Supplier ID.
	 * @return array
	 */
	public static function get_csv_mappings( string $supplier_id ): array {
		$setting_key = "mmi_vip_csv_mappings_{$supplier_id}";
		$value       = self::get_setting( $setting_key );

		if ( $value !== null ) {
			if ( is_array( $value ) ) {
				return $value;
			}
			$decoded = json_decode( (string) $value, true );
			return is_array( $decoded ) ? $decoded : [];
		}

		// Fallback: legacy table.
		global $wpdb;
		$legacy = self::csv_mappings_table();
		$row    = $wpdb->get_row(
			$wpdb->prepare( "SELECT mappings FROM $legacy WHERE supplier_id = %s", $supplier_id ),
			ARRAY_A
		);

		if ( $row ) {
			$decoded = json_decode( $row['mappings'], true );
			return is_array( $decoded ) ? $decoded : [];
		}

		return [];
	}

	/**
	 * Save CSV column mappings for a supplier.
	 *
	 * Step D: writes to vip_settings (key: mmi_vip_csv_mappings_{supplier_id}).
	 *
	 * @param  string $supplier_id Supplier ID.
	 * @param  array  $mappings    Mappings array.
	 * @return bool
	 */
	public static function set_csv_mappings( string $supplier_id, array $mappings ): bool {
		$setting_key = "mmi_vip_csv_mappings_{$supplier_id}";
		return self::set_setting( $setting_key, wp_json_encode( $mappings ) );
	}

	// ---------------------------------------------------------------------------
	// Import Field Stats API  (Step D: no-op — table retired)
	// ---------------------------------------------------------------------------

	/**
	 * Persist per-field change stats for a completed import run.
	 *
	 * Step D: the import_field_stats table is retired.  This method is a no-op
	 * so callers (ProductImportController) continue to work without changes.
	 *
	 * @param int   $run_id ID from wp_mmi_vip_import_history.
	 * @param array $stats  Nested stats array (ignored).
	 */
	public static function save_import_field_stats( int $run_id, array $stats ): void {
		// No-op: field-level stats are no longer persisted to a dedicated table.
	}

	/**
	 * Get field-level change stats for a single import run.
	 *
	 * Step D: always returns an empty array since the backing table is retired.
	 *
	 * @param  int $run_id
	 * @return array
	 */
	public static function get_import_field_stats( int $run_id ): array {
		return [];
	}

	/**
	 * Aggregate field change trends across the last $limit import runs.
	 *
	 * Step D: always returns the empty chart structure since the backing table
	 * is retired.
	 *
	 * @param  int $limit Number of recent runs (ignored).
	 * @return array
	 */
	public static function get_field_trend_data( int $limit = 20 ): array {
		return [ 'labels' => [], 'run_ids' => [], 'fields' => [] ];
	}

	// ---------------------------------------------------------------------------
	// Import Field Change Items API (per-product detail behind the "Field
	// changes" results table — see import_field_change_items_table()'s docblock)
	// ---------------------------------------------------------------------------

	/**
	 * Record one product's changed value for one field, for later spot-check
	 * lookup. Caller is responsible for the MAX_FIELD_CHANGE_ITEMS cap (see
	 * ProductImportController) — this method just inserts what it's given.
	 */
	public static function record_field_change_item( int $run_id, string $supplier_id, string $field_name, int $product_id, string $sku, string $product_title, string $old_value, string $new_value ): void {
		global $wpdb;
		$wpdb->insert(
			self::import_field_change_items_table(),
			[
				'run_id'        => $run_id,
				'supplier_id'   => $supplier_id,
				'field_name'    => $field_name,
				'product_id'    => $product_id,
				'sku'           => mb_substr( $sku, 0, 255 ),
				'product_title' => mb_substr( $product_title, 0, 255 ),
				'old_value'     => mb_substr( $old_value, 0, 500 ),
				'new_value'     => mb_substr( $new_value, 0, 500 ),
			],
			[ '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' ]
		);
	}

	/**
	 * Count of detail rows already recorded for (run, supplier, field) — used
	 * by the caller to decide whether the MAX_FIELD_CHANGE_ITEMS cap has been
	 * reached. Cheap indexed COUNT against run_field (run_id, supplier_id, field_name).
	 */
	public static function count_field_change_items( int $run_id, string $supplier_id, string $field_name ): int {
		global $wpdb;
		$table = self::import_field_change_items_table();
		return (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE run_id = %d AND supplier_id = %s AND field_name = %s",
			$run_id, $supplier_id, $field_name
		) );
	}

	/**
	 * One page of the products affected by one field's change during one run —
	 * powers the "Field changes" table's row-expand spot-check UI.
	 *
	 * @return array{items: array, total: int}
	 */
	public static function get_field_change_items( int $run_id, string $supplier_id, string $field_name, int $page = 1, int $per_page = 25 ): array {
		global $wpdb;
		$table    = self::import_field_change_items_table();
		$page     = max( 1, $page );
		$per_page = max( 1, min( 100, $per_page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM $table WHERE run_id = %d AND supplier_id = %s AND field_name = %s",
			$run_id, $supplier_id, $field_name
		) );

		$items = $wpdb->get_results( $wpdb->prepare(
			"SELECT product_id, sku, product_title, old_value, new_value, created_at
			 FROM $table
			 WHERE run_id = %d AND supplier_id = %s AND field_name = %s
			 ORDER BY id ASC
			 LIMIT %d OFFSET %d",
			$run_id, $supplier_id, $field_name, $per_page, $offset
		), ARRAY_A );

		return [ 'items' => $items ?: [], 'total' => $total ];
	}

	/**
	 * Bound the table's growth — called opportunistically when a new import
	 * run starts (see ProductImportController::run_manual_import()), not on a
	 * cron. Deletes detail rows older than $days; the aggregate counts these
	 * rows back a "Field changes" spot-check for aren't affected, since they
	 * live in the completed run's own JSON response, not this table.
	 */
	public static function prune_old_field_change_items( int $days = 14 ): void {
		global $wpdb;
		$table = self::import_field_change_items_table();
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			$days
		) );
	}

	// ---------------------------------------------------------------------------
	// Utility: increment a numeric counter setting
	// ---------------------------------------------------------------------------

	/**
	 * Atomically increment a numeric setting by a given amount.
	 *
	 * @param  string $key    Setting key.
	 * @param  int    $amount Amount to add (default 1).
	 * @return int    New value.
	 */
	public static function increment_setting( string $key, int $amount = 1 ): int {
		$current = (int) self::get_setting( $key, 0 );
		$new     = $current + $amount;
		self::set_setting( $key, $new );
		return $new;
	}

	// ---------------------------------------------------------------------------
	// Taxonomy Mappings API
	// ---------------------------------------------------------------------------

	/**
	 * Get all taxonomy mappings, optionally filtered by supplier/taxonomy.
	 *
	 * Returns an array of rows:
	 *   [ id, supplier_id, source_field, source_value, wc_taxonomy, wc_term_id, auto_create ]
	 */
	public static function get_tax_mappings( string $supplier_id = '', string $wc_taxonomy = '' ): array {
		global $wpdb;
		$table = self::tax_mappings_table();

		$where  = [];
		$params = [];

		if ( $supplier_id !== '' ) {
			$where[]  = 'supplier_id = %s';
			$params[] = $supplier_id;
		}
		if ( $wc_taxonomy !== '' ) {
			$where[]  = 'wc_taxonomy = %s';
			$params[] = $wc_taxonomy;
		}

		$sql = "SELECT * FROM $table";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY source_field ASC, source_value ASC';

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$rows = $wpdb->get_results( $sql, ARRAY_A );
		}

		return $rows ?: [];
	}

	/**
	 * Upsert a single taxonomy mapping.
	 *
	 * @param  string $supplier_id  Supplier slug ('xchange', 'skuport', …) or '' for global.
	 * @param  string $source_field Source JSON field name (e.g. 'brand').
	 * @param  string $source_value Raw value from source data.
	 * @param  string $wc_taxonomy  Target WC taxonomy (e.g. 'product_brand').
	 * @param  int    $wc_term_id   WC term ID; 0 = unmapped, -1 = skip.
	 * @param  bool   $auto_create  If true, create the term when it doesn't exist.
	 * @param  string $profile_id   Import Profile ID this row applies to, or '' for
	 *                              every profile (see resolve_tax_mapping()'s tiering).
	 * @return bool
	 */
	public static function set_tax_mapping(
		string $supplier_id,
		string $source_field,
		string $source_value,
		string $wc_taxonomy,
		int    $wc_term_id,
		bool   $auto_create = false,
		string $profile_id = ''
	): bool {
		global $wpdb;
		$table = self::tax_mappings_table();

		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO $table (supplier_id, profile_id, source_field, source_value, wc_taxonomy, wc_term_id, auto_create, updated_at)
				 VALUES (%s, %s, %s, %s, %s, %d, %d, NOW())
				 ON DUPLICATE KEY UPDATE wc_term_id = VALUES(wc_term_id), auto_create = VALUES(auto_create), updated_at = NOW()",
				$supplier_id,
				$profile_id,
				$source_field,
				$source_value,
				$wc_taxonomy,
				$wc_term_id,
				$auto_create ? 1 : 0
			)
		);

		return $result !== false;
	}

	/**
	 * Delete a taxonomy mapping by ID.
	 */
	public static function delete_tax_mapping( int $id ): bool {
		global $wpdb;
		$table  = self::tax_mappings_table();
		$result = $wpdb->delete( $table, [ 'id' => $id ], [ '%d' ] );
		return $result !== false;
	}

	/**
	 * Look up the mapped WC term ID for a specific source value.
	 * Returns null if no mapping is found.
	 * Returns -1 if the value is explicitly marked "skip".
	 */
	public static function resolve_tax_mapping(
		string $supplier_id,
		string $source_field,
		string $source_value,
		string $wc_taxonomy,
		string $profile_id = ''
	): ?int {
		global $wpdb;
		$table = self::tax_mappings_table();

		// Most-specific-wins across two independent axes (supplier, profile).
		// Supplier specificity breaks ties before profile specificity — the
		// raw value being aliased is inherently supplier-specific vocabulary,
		// so "this profile, any supplier" is the rarer case and sits behind
		// "this supplier, any profile" (tier 2, which is every existing row's
		// exact pre-profile-scoping behavior, unchanged). Pairs collapse
		// naturally (and are deduped below) when either input is already ''.
		$tiers = [];
		foreach ( [ [ $supplier_id, $profile_id ], [ $supplier_id, '' ], [ '', $profile_id ], [ '', '' ] ] as $pair ) {
			if ( ! in_array( $pair, $tiers, true ) ) {
				$tiers[] = $pair;
			}
		}

		foreach ( $tiers as [ $sid, $pid ] ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT wc_term_id, auto_create FROM $table
					 WHERE supplier_id = %s AND profile_id = %s AND source_field = %s AND source_value = %s AND wc_taxonomy = %s
					 LIMIT 1",
					$sid,
					$pid,
					$source_field,
					$source_value,
					$wc_taxonomy
				),
				ARRAY_A
			);

			if ( $row !== null ) {
				$term_id     = (int) $row['wc_term_id'];
				$auto_create = (bool) $row['auto_create'];

				if ( $term_id === -1 ) {
					return -1; // Explicitly skipped
				}
				if ( $term_id > 0 ) {
					// Verify term still exists
					$term = get_term( $term_id, $wc_taxonomy );
					if ( $term && ! is_wp_error( $term ) ) {
						return $term_id;
					}
					// Term was deleted — fall through to auto_create logic
				}
				if ( $auto_create && ! empty( $source_value ) ) {
					// Create the term using the source value as the name
					$result = wp_insert_term( $source_value, $wc_taxonomy );
					if ( ! is_wp_error( $result ) ) {
						$new_id = (int) $result['term_id'];
						// Update the stored term ID so next time we skip creation
						$wpdb->update(
							$table,
							[ 'wc_term_id' => $new_id ],
							[
								'supplier_id'  => $sid,
								'profile_id'   => $pid,
								'source_field' => $source_field,
								'source_value' => $source_value,
								'wc_taxonomy'  => $wc_taxonomy,
							],
							[ '%d' ],
							[ '%s', '%s', '%s', '%s', '%s' ]
						);
						return $new_id;
					}
				}
				return null;
			}
		}

		// No exact mapping — fall back to alias rules (tier 2 of the overall
		// resolution chain; profile-unaware today, see this session's plan
		// doc's "explicitly out of scope" note).
		return self::match_taxmap_alias_rule( $supplier_id, $source_field, $source_value, $wc_taxonomy );
	}

	// ---------------------------------------------------------------------------
	// Taxonomy Alias Rules API
	//
	// A lightweight rules tier sitting between exact-value mapping and
	// "unmapped": one rule (e.g. source value contains "roland") can catch many
	// supplier spellings instead of requiring a manual row per spelling. Stored
	// as an ordered array via MMI_Settings — array order is priority order,
	// first match wins.
	// ---------------------------------------------------------------------------

	const TAXMAP_ALIAS_RULES_KEY = 'mmi_taxmap_alias_rules';

	/**
	 * Get the ordered list of taxonomy alias rules.
	 *
	 * Each rule: [ label, supplier_id, source_field, operator, value, wc_taxonomy, wc_term_id ]
	 * supplier_id/source_field empty string = applies to any supplier/field.
	 * wc_term_id: -1 = skip.
	 */
	public static function get_taxmap_alias_rules(): array {
		$rules = MMI_Settings::get( self::TAXMAP_ALIAS_RULES_KEY, [] );
		return is_array( $rules ) ? $rules : [];
	}

	/**
	 * Replace the entire alias rules list (array order = priority order).
	 */
	public static function save_taxmap_alias_rules( array $rules ): bool {
		return MMI_Settings::set( self::TAXMAP_ALIAS_RULES_KEY, $rules );
	}

	/**
	 * Test a single rule's operator/value against a source value.
	 * String operators are case-insensitive; regex is matched as-supplied
	 * (the rule value must include its own delimiters/flags, e.g. "/^roland/i").
	 */
	public static function taxmap_rule_value_matches( string $operator, string $rule_value, string $source_value ): bool {
		if ( $rule_value === '' ) {
			return false;
		}

		if ( $operator === 'regex' ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- user-supplied pattern may be malformed
			return @preg_match( $rule_value, $source_value ) === 1;
		}

		$haystack = mb_strtolower( $source_value );
		$needle   = mb_strtolower( $rule_value );

		switch ( $operator ) {
			case 'equals':      return $haystack === $needle;
			case 'starts_with': return strpos( $haystack, $needle ) === 0;
			case 'ends_with':   return substr( $haystack, -strlen( $needle ) ) === $needle;
			case 'contains':
			default:            return strpos( $haystack, $needle ) !== false;
		}
	}

	/**
	 * Resolve a source value against the alias rules list.
	 * Rules are evaluated in stored array order; the first matching rule wins.
	 * A rule pointing at a deleted term is skipped (falls through to the next rule).
	 *
	 * @return int|null Matched wc_term_id (-1 = skip), or null if no rule matches.
	 */
	public static function match_taxmap_alias_rule(
		string $supplier_id,
		string $source_field,
		string $source_value,
		string $wc_taxonomy
	): ?int {
		foreach ( self::get_taxmap_alias_rules() as $rule ) {
			if ( ( $rule['wc_taxonomy'] ?? '' ) !== $wc_taxonomy ) {
				continue;
			}
			if ( ! empty( $rule['supplier_id'] ) && $rule['supplier_id'] !== $supplier_id ) {
				continue;
			}
			if ( ! empty( $rule['source_field'] ) && $rule['source_field'] !== $source_field ) {
				continue;
			}
			if ( ! self::taxmap_rule_value_matches( $rule['operator'] ?? 'contains', $rule['value'] ?? '', $source_value ) ) {
				continue;
			}

			$tid = (int) ( $rule['wc_term_id'] ?? 0 );
			if ( $tid === -1 ) {
				return -1;
			}
			if ( $tid > 0 ) {
				$term = get_term( $tid, $wc_taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $tid;
				}
				// Stale rule (term deleted) — keep checking remaining rules.
			}
		}

		return null;
	}

	// ---------------------------------------------------------------------------
	// Export History API
	//
	// Deliberately a settings-array (not a dedicated DB table like
	// import_history) — export runs are user-initiated or scheduled at a low
	// frequency per profile, not a high-volume hourly-tick process across many
	// suppliers, so a capped JSON array is proportionate. Revisit as a real
	// table only if export volume/history needs ever grow to justify it.
	// ---------------------------------------------------------------------------

	const EXPORT_HISTORY_KEY = 'mmi_pipeline_export_history';
	const EXPORT_HISTORY_MAX = 50;

	/**
	 * Get export history entries, newest first.
	 *
	 * @param int $limit Maximum number of records.
	 * @return array
	 */
	public static function get_export_history( int $limit = 50 ): array {
		$history = self::get_setting( self::EXPORT_HISTORY_KEY, [] );
		if ( ! is_array( $history ) ) {
			return [];
		}
		return array_slice( $history, 0, $limit );
	}

	/**
	 * Prepend a new export history entry, capped at EXPORT_HISTORY_MAX entries.
	 *
	 * @param array $entry Expected keys: profile_id, data_type, format, started_at,
	 *                      completed_at, total_records, records_exported, records_failed, status.
	 * @return bool
	 */
	public static function append_export_history( array $entry ): bool {
		$history = self::get_setting( self::EXPORT_HISTORY_KEY, [] );
		if ( ! is_array( $history ) ) {
			$history = [];
		}
		array_unshift( $history, $entry );
		$history = array_slice( $history, 0, self::EXPORT_HISTORY_MAX );
		return self::set_setting( self::EXPORT_HISTORY_KEY, $history );
	}
}
