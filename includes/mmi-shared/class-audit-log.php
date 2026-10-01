<?php
/**
 * MMI_Audit_Log — append-only, tamper-evident audit trail (bundled/vendored copy).
 *
 * One table for the whole suite, {prefix}mmi_audit_log, written by whichever
 * plugin's bundled copy wins version negotiation (ADR-0006). Records WHO did
 * WHAT to WHICH object, from WHERE, and with what OUTCOME: settings and
 * credential changes, destructive/bulk operations, data exports, money- or
 * fulfillment-side-effect actions, customer self-service changes and denied
 * privileged requests. MMI_Logger stays the operational/debug log; this is
 * the evidence log a SOC 2 reviewer asks for.
 *
 * Integrity: each row stores sha256( prev_hash | canonical row ), so editing
 * or deleting a row in the middle breaks every hash after it — verify()
 * reports the first broken id. Rows are also mirrored to the 'audit'
 * MMI_Logger file, a second copy outside the database. The class exposes no
 * update or delete; the only removal is retention pruning of the oldest
 * rows (default 365 days, filter 'mmi_audit_log_retention_days'), and the
 * oldest surviving row then anchors the chain.
 *
 * Details are redacted before storage: any key that names a secret
 * (password, token, secret, api_key, authorization, card, cvv, nonce,
 * cookie, signature …) is replaced, at any depth. Callers must still pass
 * setting KEYS that changed, never their values.
 *
 * Viewer: MannMade → Audit Log (capability filter
 * 'mmi_audit_log_view_capability', default manage_options) and
 * `wp mmi-audit list|verify` under WP-CLI.
 *
 * Do not hand-edit this file in a single plugin; edit the canonical source
 * (mmi-admin/lib/mmi-shared/) and re-sync (sync-to-plugins.sh).
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Audit_Log {

	const SCHEMA_VERSION = 1;
	const PAGE_SLUG      = 'mmi-audit-log';
	const PRUNE_HOOK     = 'mmi_audit_log_prune';
	const MAX_DETAILS    = 8192; // bytes of JSON kept per row
	const OUTCOMES       = array( 'success', 'failure', 'denied' );

	/**
	 * Key names whose values are never stored. Matched as a whole
	 * underscore/dash-separated segment, so 'changed_keys' is kept but
	 * 'api_key', 'client_secret' and 'card_number' are not.
	 */
	const REDACT_PATTERN = '/(^|[_\-])(pass(word|wd|phrase)?|secret|token|api[_\-]?key|private[_\-]?key|license[_\-]?key|auth(orization)?|bearer|card(_?number)?|cvc|cvv|pan|iban|ssn|nonce|cookie|signature|salt|credentials?)([_\-]|$)/i';

	private static bool $table_ensured = false;

	/* ── Public API ─────────────────────────────────────────────────── */

	/**
	 * Record one audit event. Never throws and never blocks the caller's
	 * action: an audit write failure is reported to MMI_Logger instead.
	 *
	 * @param string $plugin Plugin slug, e.g. 'mmi-subscriptions'.
	 * @param string $action Dot-separated verb, e.g. 'subscription.cancel'.
	 * @param array  $args   object_type, object_id, outcome (success|failure|denied), details (array).
	 */
	public static function record( string $plugin, string $action, array $args = array() ): void {
		global $wpdb;

		try {
			$table = self::table();

			$outcome = (string) ( $args['outcome'] ?? 'success' );
			$outcome = in_array( $outcome, self::OUTCOMES, true ) ? $outcome : 'success';
			$details = self::encode_details( $args['details'] ?? array() );

			list( $user_id, $user_login ) = self::actor();

			$row = array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => $user_id,
				'user_login'  => substr( $user_login, 0, 60 ),
				'ip'          => self::client_ip(),
				'plugin'      => substr( sanitize_key( $plugin ), 0, 64 ),
				'action'      => substr( preg_replace( '/[^a-z0-9._\-]/', '', strtolower( $action ) ), 0, 100 ),
				'object_type' => substr( sanitize_key( (string) ( $args['object_type'] ?? '' ) ), 0, 64 ),
				'object_id'   => substr( (string) ( $args['object_id'] ?? '' ), 0, 100 ),
				'outcome'     => $outcome,
				'details'     => $details,
				'request'     => self::request_context(),
			);

			// Serialize the chain: two concurrent writers must not both
			// link to the same predecessor. Low volume, so a short wait is fine.
			$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', $table ) );

			$prev            = (string) $wpdb->get_var( "SELECT row_hash FROM {$table} ORDER BY id DESC LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
			$row['prev_hash'] = $prev;
			$row['row_hash']  = self::hash_row( $row, $prev );

			// Explicit formats: core's $wpdb->field_types maps object_id/user_id
			// to %d, which would store '' as 0 and break the hash on re-read.
			$formats = array_fill_keys( array_keys( $row ), '%s' );
			$formats['user_id'] = '%d';
			$ok = $wpdb->insert( $table, $row, array_values( $formats ) );

			if ( $locked ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $table ) );
			}

			if ( ! $ok ) {
				throw new RuntimeException( 'insert failed: ' . $wpdb->last_error );
			}

			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::info(
					$row['action'],
					array(
						'id'      => (int) $wpdb->insert_id,
						'plugin'  => $row['plugin'],
						'user'    => $row['user_login'] . '#' . $row['user_id'],
						'ip'      => $row['ip'],
						'object'  => trim( $row['object_type'] . ':' . $row['object_id'], ':' ),
						'outcome' => $outcome,
						'hash'    => substr( $row['row_hash'], 0, 16 ),
					),
					'audit',
					'MMI_Audit_Log'
				);
			}
		} catch ( \Throwable $e ) {
			if ( class_exists( 'MMI_Logger' ) ) {
				MMI_Logger::error( 'Audit write failed', array( 'action' => $action, 'error' => $e->getMessage() ), 'security', 'MMI_Audit_Log' );
			}
		}
	}

	/**
	 * Shorthand for a refused privileged request (failed capability or
	 * nonce). Call it right before the handler's own 403 response.
	 */
	public static function denied( string $plugin, string $action, string $reason = 'capability' ): void {
		self::record( $plugin, $action, array( 'outcome' => 'denied', 'details' => array( 'reason' => $reason ) ) );
	}

	/**
	 * Names of the keys that differ between two settings arrays — what a
	 * settings.update event should carry instead of the values.
	 */
	public static function changed_keys( array $before, array $after ): array {
		$keys    = array_unique( array_merge( array_keys( $before ), array_keys( $after ) ) );
		$changed = array();
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $before ) || ! array_key_exists( $key, $after ) || $before[ $key ] !== $after[ $key ] ) {
				$changed[] = (string) $key;
			}
		}
		sort( $changed );
		return $changed;
	}

	/**
	 * Walk the chain oldest → newest.
	 *
	 * @return array{ok:bool,checked:int,broken_id:int,reason:string}
	 */
	public static function verify( int $batch = 2000 ): array {
		global $wpdb;
		$table   = self::table();
		$prev    = null;
		$last_id = 0;
		$checked = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $last_id, $batch ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $rows as $row ) {
				// The oldest surviving row anchors the chain (older rows may be pruned).
				if ( $prev !== null && ! hash_equals( $prev, (string) $row['prev_hash'] ) ) {
					return array( 'ok' => false, 'checked' => $checked, 'broken_id' => (int) $row['id'], 'reason' => 'prev_hash does not match the previous row (row removed or reordered)' );
				}
				if ( ! hash_equals( self::hash_row( $row, (string) $row['prev_hash'] ), (string) $row['row_hash'] ) ) {
					return array( 'ok' => false, 'checked' => $checked, 'broken_id' => (int) $row['id'], 'reason' => 'row contents do not match row_hash (row edited)' );
				}
				$prev    = (string) $row['row_hash'];
				$last_id = (int) $row['id'];
				++$checked;
			}
		} while ( count( $rows ) === $batch );

		return array( 'ok' => true, 'checked' => $checked, 'broken_id' => 0, 'reason' => '' );
	}

	/**
	 * Read rows for the viewer / CLI. Every filter is bound, every sort
	 * column whitelisted.
	 *
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $filters = array(), int $page = 1, int $per_page = 50, string $orderby = 'id', string $order = 'DESC' ): array {
		global $wpdb;
		$table = self::table();

		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'plugin', 'action', 'outcome', 'user_login', 'object_type', 'object_id', 'ip' ) as $col ) {
			if ( isset( $filters[ $col ] ) && $filters[ $col ] !== '' ) {
				$where[]  = "{$col} = %s";
				$params[] = (string) $filters[ $col ];
			}
		}
		if ( ! empty( $filters['from'] ) ) {
			$where[]  = 'created_at >= %s';
			$params[] = $filters['from'] . ' 00:00:00';
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]  = 'created_at <= %s';
			$params[] = $filters['to'] . ' 23:59:59';
		}

		$sortable = array( 'id', 'created_at', 'user_login', 'ip', 'plugin', 'action', 'object_type', 'object_id', 'outcome' );
		$orderby  = in_array( $orderby, $sortable, true ) ? $orderby : 'id';
		$order    = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
		$per_page = max( 1, min( 500, $per_page ) );
		$offset   = max( 0, ( $page - 1 ) * $per_page );

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$rows_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- columns whitelisted above, values bound.
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( $rows_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable

		return array( 'rows' => $rows ?: array(), 'total' => $total );
	}

	/** Delete rows older than the retention window. Runs daily via cron. */
	public static function prune(): void {
		global $wpdb;
		$days = (int) apply_filters( 'mmi_audit_log_retention_days', 365 );
		if ( $days < 30 ) {
			$days = 30; // Never let a filter wipe recent evidence.
		}
		$table   = self::table();
		$cutoff  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s LIMIT 5000", $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $deleted > 0 ) {
			self::record( 'mmi-shared', 'audit.prune', array( 'details' => array( 'deleted' => $deleted, 'retention_days' => $days ) ) );
		}
	}

	/* ── Hooks (registered once from bootstrap.php) ─────────────────── */

	public static function register_hooks(): void {
		add_action( self::PRUNE_HOOK, array( __CLASS__, 'prune' ) );
		add_action( 'init', array( __CLASS__, 'schedule_prune' ) );
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_action( 'admin_post_mmi_audit_log_export', array( __CLASS__, 'handle_export' ) );

		// Core authentication events, recorded once for the whole site.
		add_action( 'wp_login', array( __CLASS__, 'on_login' ), 10, 2 );
		add_action( 'wp_login_failed', array( __CLASS__, 'on_login_failed' ) );
		add_action( 'set_user_role', array( __CLASS__, 'on_role_change' ), 10, 3 );
		add_action( 'activated_plugin', array( __CLASS__, 'on_plugin_toggle_on' ) );
		add_action( 'deactivated_plugin', array( __CLASS__, 'on_plugin_toggle_off' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			\WP_CLI::add_command( 'mmi-audit', array( __CLASS__, 'cli' ) );
		}
	}

	public static function schedule_prune(): void {
		if ( ! wp_next_scheduled( self::PRUNE_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PRUNE_HOOK );
		}
	}

	public static function on_login( $user_login, $user = null ): void {
		self::record( 'wordpress', 'auth.login', array( 'object_type' => 'user', 'object_id' => $user instanceof WP_User ? $user->ID : '', 'details' => array( 'login' => (string) $user_login ) ) );
	}

	public static function on_login_failed( $username ): void {
		// A brute-force run must not flood the table: at most 20 rows per IP per hour.
		$key   = 'mmi_audit_lf_' . md5( self::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= 20 ) {
			return;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		self::record( 'wordpress', 'auth.login_failed', array( 'outcome' => 'failure', 'details' => array( 'login' => substr( (string) $username, 0, 60 ) ) ) );
	}

	public static function on_role_change( $user_id, $role, $old_roles ): void {
		self::record( 'wordpress', 'user.role_change', array( 'object_type' => 'user', 'object_id' => (int) $user_id, 'details' => array( 'role' => (string) $role, 'old_roles' => array_values( (array) $old_roles ) ) ) );
	}

	public static function on_plugin_toggle_on( $plugin ): void {
		self::record( 'wordpress', 'plugin.activate', array( 'object_type' => 'plugin', 'object_id' => (string) $plugin ) );
	}

	public static function on_plugin_toggle_off( $plugin ): void {
		self::record( 'wordpress', 'plugin.deactivate', array( 'object_type' => 'plugin', 'object_id' => (string) $plugin ) );
	}

	/* ── Viewer ─────────────────────────────────────────────────────── */

	private static function view_cap(): string {
		return (string) apply_filters( 'mmi_audit_log_view_capability', 'manage_options' );
	}

	public static function add_menu(): void {
		$cap = self::view_cap();
		if ( ! current_user_can( $cap ) ) {
			return;
		}
		global $admin_page_hooks;
		$parent = isset( $admin_page_hooks['mmi-dashboard'] ) ? 'mmi-dashboard' : 'tools.php';
		add_submenu_page( $parent, __( 'Audit Log', 'mmi-shared' ), __( 'Audit Log', 'mmi-shared' ), $cap, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
	}

	/** Filters as URL query args (action column travels as ?event=). */
	private static function filter_args( array $filters ): array {
		$args = array_filter( $filters );
		if ( isset( $args['action'] ) ) {
			$args['event'] = $args['action'];
			unset( $args['action'] );
		}
		return $args;
	}

	private static function request_filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$f = array();
		// The action column is filtered via ?event= — admin-post.php owns ?action=.
		$params = array( 'plugin' => 'plugin', 'action' => 'event', 'outcome' => 'outcome', 'user_login' => 'user_login', 'object_type' => 'object_type', 'object_id' => 'object_id', 'ip' => 'ip' );
		foreach ( $params as $k => $param ) {
			$f[ $k ] = isset( $_GET[ $param ] ) ? sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) : '';
		}
		foreach ( array( 'from', 'to' ) as $k ) {
			$v       = isset( $_GET[ $k ] ) ? sanitize_text_field( wp_unslash( $_GET[ $k ] ) ) : '';
			$f[ $k ] = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : '';
		}
		// phpcs:enable
		return $f;
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::view_cap() ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-shared' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view.
		$filters = self::request_filters();
		$paged   = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$orderby = sanitize_key( $_GET['orderby'] ?? 'id' );
		$order   = sanitize_key( $_GET['order'] ?? 'desc' );
		$verify  = ! empty( $_GET['verify'] ) ? self::verify() : null;
		// phpcs:enable

		$result = self::query( $filters, $paged, 50, $orderby, $order );
		$pages  = max( 1, (int) ceil( $result['total'] / 50 ) );
		$base   = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		echo '<div class="wrap mmi-page mmi-audit-log">';
		echo '<div class="mmi-header"><h1><span class="dashicons dashicons-shield"></span> ' . esc_html__( 'Audit Log', 'mmi-shared' ) . '</h1>';
		echo '<p class="mmi-header-description">' . esc_html__( 'Append-only record of security-relevant actions across MannMade plugins. Rows are hash-chained; any edit or removal shows up in verification.', 'mmi-shared' ) . '</p></div>';
		echo '<div class="wp-header-end"></div>';

		if ( $verify !== null ) {
			$class = $verify['ok'] ? 'notice-success' : 'notice-error';
			$text  = $verify['ok']
				/* translators: %d: number of rows checked */
				? sprintf( __( 'Chain intact: %d rows verified.', 'mmi-shared' ), $verify['checked'] )
				/* translators: 1: row id, 2: reason */
				: sprintf( __( 'Chain broken at row #%1$d: %2$s', 'mmi-shared' ), $verify['broken_id'], $verify['reason'] );
			echo '<div class="notice ' . esc_attr( $class ) . ' inline"><p>' . esc_html( $text ) . '</p></div>';
		}

		echo '<div class="mmi-process-section"><div class="mmi-section-header"><h3 class="mmi-process-section-header"><span class="dashicons dashicons-filter"></span> ' . esc_html__( 'Events', 'mmi-shared' ) . '</h3>';
		echo '<p class="mmi-process-section-description">' . esc_html__( 'Filter by any column; times are UTC.', 'mmi-shared' ) . '</p></div><div class="mmi-section-content">';

		echo '<form method="get" class="mmi-audit-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		$labels = array(
			'plugin'     => __( 'Plugin', 'mmi-shared' ),
			'action'     => __( 'Action', 'mmi-shared' ),
			'outcome'    => __( 'Outcome', 'mmi-shared' ),
			'user_login' => __( 'User', 'mmi-shared' ),
			'object_id'  => __( 'Object ID', 'mmi-shared' ),
			'ip'         => __( 'IP', 'mmi-shared' ),
		);
		foreach ( $labels as $k => $label ) {
			printf( '<input type="text" name="%1$s" value="%2$s" placeholder="%3$s" aria-label="%3$s"> ', esc_attr( $k === 'action' ? 'event' : $k ), esc_attr( $filters[ $k ] ), esc_attr( $label ) );
		}
		printf( '<input type="date" name="from" value="%1$s" aria-label="%2$s"> ', esc_attr( $filters['from'] ), esc_attr__( 'From', 'mmi-shared' ) );
		printf( '<input type="date" name="to" value="%1$s" aria-label="%2$s"> ', esc_attr( $filters['to'] ), esc_attr__( 'To', 'mmi-shared' ) );
		echo '<button type="submit" class="button">' . esc_html__( 'Filter', 'mmi-shared' ) . '</button> ';
		echo '<a class="button" href="' . esc_url( add_query_arg( 'verify', 1, $base ) ) . '">' . esc_html__( 'Verify chain', 'mmi-shared' ) . '</a> ';
		$export = wp_nonce_url( add_query_arg( array_merge( self::filter_args( $filters ), array( 'action' => 'mmi_audit_log_export' ) ), admin_url( 'admin-post.php' ) ), 'mmi_audit_log_export' );
		echo '<a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'mmi-shared' ) . '</a>';
		echo '</form>';

		$cols = array(
			'created_at'  => __( 'Time (UTC)', 'mmi-shared' ),
			'user_login'  => __( 'User', 'mmi-shared' ),
			'ip'          => __( 'IP', 'mmi-shared' ),
			'plugin'      => __( 'Plugin', 'mmi-shared' ),
			'action'      => __( 'Action', 'mmi-shared' ),
			'object_type' => __( 'Object', 'mmi-shared' ),
			'outcome'     => __( 'Outcome', 'mmi-shared' ),
			'details'     => __( 'Details', 'mmi-shared' ),
		);

		echo '<div class="mmi-table-scroll-wrapper"><table class="widefat striped mmi-table" data-mmi-sort="server" data-mmi-resizable="1"><thead><tr>';
		foreach ( $cols as $key => $label ) {
			if ( $key === 'details' ) {
				echo '<th data-resize-col="details">' . esc_html( $label ) . '</th>';
				continue;
			}
			$next = ( $orderby === $key && strtolower( $order ) === 'desc' ) ? 'asc' : 'desc';
			$url  = add_query_arg( array_merge( self::filter_args( $filters ), array( 'orderby' => $key, 'order' => $next ) ), $base );
			printf( '<th data-sort-key="%1$s" data-resize-col="%1$s"><a href="%2$s">%3$s</a></th>', esc_attr( $key ), esc_url( $url ), esc_html( $label ) );
		}
		echo '</tr></thead><tbody>';

		if ( ! $result['rows'] ) {
			echo '<tr><td colspan="' . count( $cols ) . '">' . esc_html__( 'No events match.', 'mmi-shared' ) . '</td></tr>';
		}
		foreach ( $result['rows'] as $row ) {
			echo '<tr>';
			echo '<td>' . esc_html( $row['created_at'] ) . '</td>';
			echo '<td>' . esc_html( $row['user_login'] . ( (int) $row['user_id'] ? ' #' . $row['user_id'] : '' ) ) . '</td>';
			echo '<td>' . esc_html( $row['ip'] ) . '</td>';
			echo '<td>' . esc_html( $row['plugin'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['action'] ) . '</code></td>';
			echo '<td>' . esc_html( trim( $row['object_type'] . ' ' . $row['object_id'] ) ) . '</td>';
			echo '<td>' . esc_html( $row['outcome'] ) . '</td>';
			echo '<td><code>' . esc_html( $row['details'] ) . '</code></td>';
			echo '</tr>';
		}
		echo '</tbody></table></div>';

		if ( $pages > 1 ) {
			echo '<p class="mmi-audit-pages">';
			/* translators: 1: current page, 2: total pages, 3: total rows */
			echo esc_html( sprintf( __( 'Page %1$d of %2$d (%3$d events)', 'mmi-shared' ), $paged, $pages, $result['total'] ) ) . ' ';
			$keep = array_merge( self::filter_args( $filters ), array( 'orderby' => $orderby, 'order' => $order ) );
			if ( $paged > 1 ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $keep, array( 'paged' => $paged - 1 ) ), $base ) ) . '">&lsaquo;</a> ';
			}
			if ( $paged < $pages ) {
				echo '<a class="button" href="' . esc_url( add_query_arg( array_merge( $keep, array( 'paged' => $paged + 1 ) ), $base ) ) . '">&rsaquo;</a>';
			}
			echo '</p>';
		}

		echo '</div></div></div>';
	}

	public static function handle_export(): void {
		if ( ! current_user_can( self::view_cap() ) ) {
			self::denied( 'mmi-shared', 'audit.export' );
			wp_die( esc_html__( 'Insufficient permissions.', 'mmi-shared' ), 403 );
		}
		check_admin_referer( 'mmi_audit_log_export' );

		$filters = self::request_filters();
		self::record( 'mmi-shared', 'audit.export', array( 'details' => array( 'filters' => array_filter( $filters ) ) ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mmi-audit-log-' . gmdate( 'Ymd-His' ) . '.csv"' );

		$out  = fopen( 'php://output', 'w' );
		$head = array( 'id', 'created_at', 'user_id', 'user_login', 'ip', 'plugin', 'action', 'object_type', 'object_id', 'outcome', 'details', 'request', 'prev_hash', 'row_hash' );
		fputcsv( $out, $head );
		$page = 1;
		do {
			$batch = self::query( $filters, $page++, 500, 'id', 'ASC' );
			foreach ( $batch['rows'] as $row ) {
				$line = array();
				foreach ( $head as $col ) {
					// Neutralize spreadsheet formula injection.
					$v      = (string) ( $row[ $col ] ?? '' );
					$line[] = preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
				}
				fputcsv( $out, $line );
			}
		} while ( count( $batch['rows'] ) === 500 );
		fclose( $out );
		exit;
	}

	/**
	 * WP-CLI: `wp mmi-audit verify` or `wp mmi-audit list [--limit=50]`.
	 */
	public static function cli( $args, $assoc ): void {
		$sub = $args[0] ?? 'list';
		if ( $sub === 'verify' ) {
			$r = self::verify();
			if ( $r['ok'] ) {
				\WP_CLI::success( "Chain intact: {$r['checked']} rows verified." );
			} else {
				\WP_CLI::error( "Chain broken at row #{$r['broken_id']}: {$r['reason']}" );
			}
			return;
		}
		$limit = max( 1, min( 500, (int) ( $assoc['limit'] ?? 50 ) ) );
		$rows  = self::query( array(), 1, $limit )['rows'];
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'created_at', 'user_login', 'ip', 'plugin', 'action', 'object_type', 'object_id', 'outcome' ) );
	}

	/* ── Internals ──────────────────────────────────────────────────── */

	private static function table(): string {
		global $wpdb;
		$table = $wpdb->prefix . 'mmi_audit_log';
		if ( ! self::$table_ensured ) {
			self::$table_ensured = true;
			self::maybe_create_table( $table );
		}
		return $table;
	}

	private static function maybe_create_table( string $table ): void {
		global $wpdb;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
            user_login VARCHAR(60) NOT NULL DEFAULT '',
            ip VARCHAR(45) NOT NULL DEFAULT '',
            plugin VARCHAR(64) NOT NULL DEFAULT '',
            action VARCHAR(100) NOT NULL DEFAULT '',
            object_type VARCHAR(64) NOT NULL DEFAULT '',
            object_id VARCHAR(100) NOT NULL DEFAULT '',
            outcome VARCHAR(10) NOT NULL DEFAULT 'success',
            details TEXT NOT NULL,
            request VARCHAR(255) NOT NULL DEFAULT '',
            prev_hash CHAR(64) NOT NULL DEFAULT '',
            row_hash CHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            KEY created_at (created_at),
            KEY plugin_action (plugin, action),
            KEY user_id (user_id),
            KEY object (object_type, object_id)
        ) {$charset_collate};"
		);
		if ( class_exists( 'MMI_Logger' ) ) {
			MMI_Logger::info( 'mmi_audit_log table created', array(), 'database', 'MMI_Audit_Log' );
		}
	}

	private static function hash_row( array $row, string $prev ): string {
		$fields = array( 'created_at', 'user_id', 'user_login', 'ip', 'plugin', 'action', 'object_type', 'object_id', 'outcome', 'details', 'request' );
		$parts  = array( $prev );
		foreach ( $fields as $f ) {
			$parts[] = (string) ( $row[ $f ] ?? '' );
		}
		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	/** @return array{0:int,1:string} */
	private static function actor(): array {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id ) {
			$user = wp_get_current_user();
			return array( $user_id, (string) $user->user_login );
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return array( 0, 'wp-cli' );
		}
		if ( wp_doing_cron() ) {
			return array( 0, 'cron' );
		}
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return array( 0, 'rest-anonymous' );
		}
		return array( 0, 'anonymous' );
	}

	/**
	 * REMOTE_ADDR only. Forwarded headers are client-controlled unless the
	 * web server rewrites REMOTE_ADDR from a trusted proxy (nginx real_ip /
	 * Apache mod_remoteip) — configure that there, not here. A site that
	 * needs a different source can supply it via 'mmi_audit_log_client_ip'.
	 */
	private static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$ip = (string) apply_filters( 'mmi_audit_log_client_ip', $ip );
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/** Method + path only — query strings can carry tokens. */
	private static function request_context(): string {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return 'cli';
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $_SERVER['REQUEST_METHOD'] ) ) : '';
		$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		$ajax   = ( wp_doing_ajax() && isset( $_REQUEST['action'] ) ) ? ' action=' . sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return substr( trim( $method . ' ' . $path . $ajax ), 0, 255 );
	}

	private static function encode_details( $details ): string {
		if ( ! is_array( $details ) ) {
			$details = array( 'value' => $details );
		}
		$json = wp_json_encode( self::redact( $details ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return '{}';
		}
		if ( strlen( $json ) > self::MAX_DETAILS ) {
			$json = wp_json_encode( array( 'truncated' => true, 'bytes' => strlen( $json ), 'keys' => array_slice( array_keys( $details ), 0, 50 ) ) );
		}
		return (string) $json;
	}

	private static function redact( array $data, int $depth = 0 ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && preg_match( self::REDACT_PATTERN, $key ) ) {
				$data[ $key ] = '[REDACTED]';
			} elseif ( is_array( $value ) ) {
				$data[ $key ] = $depth < 5 ? self::redact( $value, $depth + 1 ) : '[DEPTH]';
			} elseif ( is_object( $value ) ) {
				$data[ $key ] = '[' . get_class( $value ) . ']';
			} elseif ( is_string( $value ) && preg_match( '/^(sk|rk|pk)_(live|test)_|^Bearer\s|^ey[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]+\./', $value ) ) {
				$data[ $key ] = '[REDACTED]'; // Value looks like a credential regardless of its key.
			}
		}
		return $data;
	}
}
