<?php
/**
 * MMI_Supplier_Admin
 *
 * The admin page every software-distributor integration shares (XChange,
 * SkuPort, Plugivery): one MannMade submenu page with tabs, the suite page
 * shell, section headers, the Catalog tab (mmi-data-pipeline's shared feed
 * catalog) and a Logs tab over the supplier's MMI_Logger category. Each
 * plugin's admin class extends this and supplies only what differs: its
 * tabs, header controls, assets and AJAX actions.
 *
 * Static, with late static binding: subclasses set the constants below and
 * override the hooks; templates keep calling e.g.
 * MMI_SkuPort_Admin::section_head().
 *
 * A tab renders from includes/admin/tab-{key}.php when the plugin has that
 * file, else from a render_tab_{key}() method (this class provides catalog
 * and logs).
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Supplier_Admin', false ) ) {

	abstract class MMI_Supplier_Admin {

		/** Admin page slug ('mmi-skuport'). */
		const PAGE_SLUG = '';

		/** Nonce action for this page's AJAX and forms. */
		const NONCE_ACTION = '';

		/** Tab key => label; the first is the default. */
		const TABS = array();

		/** Short element-ID prefix used by the plugin's own JS ('sk' → #mmi-sk-notice). */
		const JS_PREFIX = '';

		/** The last 256 KB of a log is far more than the lines shown. */
		const LOG_TAIL_BYTES = 262144;

		/* ── What each supplier plugin provides ─────────────────────────── */

		/** Supplier ID: the feed catalog's and the pipeline's ('skuport'). */
		abstract public static function supplier_id(): string;

		/** Display name ('SkuPort'). */
		abstract public static function supplier_label(): string;

		/** The capability this page needs, through the plugin's own filter. */
		abstract public static function capability(): string;

		/** One line under the page title. */
		abstract protected static function description(): string;

		/** The plugin's own CSS/JS (and localized config). */
		abstract protected static function enqueue_plugin_assets(): void;

		/** Directory holding tab-{key}.php files, with trailing slash. */
		abstract protected static function tab_dir(): string;

		/** Dashicon name for the page title. */
		protected static function icon(): string {
			return 'cart';
		}

		/** MMI_Logger category the Logs tab reads. */
		protected static function log_category(): string {
			return static::supplier_id();
		}

		/** What the supplier's log records, under the Logs section title. */
		protected static function log_description(): string {
			return '';
		}

		/** Escaped HTML for .mmi-header-actions (persistent controls), or ''. */
		protected static function header_actions(): string {
			return '';
		}

		/**
		 * Escaped HTML for .mmi-header-alerts (warning/error badges), or null
		 * for no alerts row. '' still renders the (empty) row, for a page
		 * whose script moves badges into it.
		 */
		protected static function header_alerts(): ?string {
			return null;
		}

		/** Runs before any output (e.g. a redirect for a retired tab). */
		protected static function before_render(): void {}

		/* ── Shared behavior ─────────────────────────────────────────────── */

		public static function user_can(): bool {
			$cap = static::capability();
			return $cap !== '' && current_user_can( $cap );
		}

		public static function init(): void {
			add_action( 'admin_menu', array( static::class, 'register_submenu' ) );
			add_action( 'admin_enqueue_scripts', array( static::class, 'enqueue_assets' ) );
		}

		public static function register_submenu(): void {
			add_submenu_page( 'mmi-dashboard', static::supplier_label(), static::supplier_label(), static::capability(), static::PAGE_SLUG, array( static::class, 'render_page' ) );
		}

		/** Substring match: the hook suffix comes from the parent menu's title, not its slug. */
		public static function enqueue_assets( string $hook ): void {
			if ( strpos( $hook, static::PAGE_SLUG ) === false ) {
				return;
			}
			static::enqueue_plugin_assets();
			// The Catalog tab only, so other tabs don't carry its script.
			$catalog = static::current_tab() === 'catalog' ? self::catalog( static::supplier_id() ) : null;
			if ( $catalog ) {
				$catalog->enqueue();
			}
		}

		public static function current_tab(): string {
			$tab = sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array_key_exists( $tab, static::TABS ) ? $tab : (string) array_key_first( static::TABS );
		}

		public static function tab_url( string $tab, array $args = array() ): string {
			return add_query_arg( array_merge( array( 'page' => static::PAGE_SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
		}

		/** Suite-standard page section header band (see style-system.md). */
		public static function section_head( string $icon, string $title, string $desc, string $actions_html = '' ): void {
			?>
			<div class="mmi-section-header">
				<div>
					<h3 class="mmi-process-section-header"><span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>"></span> <?php echo esc_html( $title ); ?></h3>
					<p class="mmi-process-section-description"><?php echo esc_html( $desc ); ?></p>
				</div>
				<?php echo $actions_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts by the caller ?>
			</div>
			<?php
		}

		public static function render_page(): void {
			if ( ! static::user_can() ) {
				return;
			}
			static::before_render();
			$tab     = static::current_tab();
			$id      = static::supplier_id();
			$prefix  = static::JS_PREFIX !== '' ? static::JS_PREFIX : $id;
			$actions = static::header_actions();
			$alerts  = static::header_alerts();
			?>
			<div class="wrap mmi-page mmi-<?php echo esc_attr( $id ); ?>-wrap">
				<div class="mmi-header">
					<h1><span class="dashicons dashicons-<?php echo esc_attr( static::icon() ); ?>"></span> <?php echo esc_html( static::supplier_label() ); ?></h1>
					<?php if ( $actions !== '' ) : ?>
						<div class="mmi-header-actions"><?php echo $actions; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by header_actions() ?></div>
					<?php endif; ?>
					<p class="mmi-header-description"><?php echo esc_html( static::description() ); ?></p>
					<?php if ( $alerts !== null ) : ?>
						<div class="mmi-header-alerts" id="mmi-<?php echo esc_attr( $prefix ); ?>-header-alerts"><?php echo $alerts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by header_alerts() ?></div>
					<?php endif; ?>
				</div>
				<div class="wp-header-end"></div>

				<nav class="nav-tab-wrapper">
					<?php foreach ( static::TABS as $key => $label ) : ?>
						<a href="<?php echo esc_url( static::tab_url( $key ) ); ?>" class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php endforeach; ?>
				</nav>

				<div id="mmi-<?php echo esc_attr( $prefix ); ?>-notice" class="notice inline" hidden><p></p></div>
				<div class="mmi-supplier-tab-content">
					<?php static::render_tab( $tab ); ?>
				</div>
			</div>
			<?php
		}

		protected static function render_tab( string $tab ): void {
			$file   = static::tab_dir() . 'tab-' . $tab . '.php';
			$method = 'render_tab_' . $tab;
			if ( is_file( $file ) ) {
				require $file;
			} elseif ( method_exists( static::class, $method ) ) {
				call_user_func( array( static::class, $method ) );
			}
		}

		/* ── Catalog tab ─────────────────────────────────────────────────── */

		private static function catalog( string $id ) {
			return class_exists( 'MMI_Pipeline_Feed_Catalog' ) ? MMI_Pipeline_Feed_Catalog::get( $id ) : null;
		}

		/**
		 * The supplier's feed as of the last fetch (MMI_Pipeline_Feed_Catalog,
		 * mmi-data-pipeline). Without Data Pipeline the tab stays, locked,
		 * saying why.
		 */
		public static function render_tab_catalog(): void {
			$catalog = self::catalog( static::supplier_id() );
			if ( $catalog ) {
				$catalog->render();
				return;
			}
			if ( class_exists( 'MMI_Feature_Gate' ) ) {
				MMI_Feature_Gate::register( 'mmi-data-pipeline', array(
					'label'       => __( 'MMI Data Pipeline', 'mmi-shared' ),
					'product_url' => 'https://mannmade.us/extensions/data-pipeline',
				) );
			}
			?>
			<div class="mmi-process-section mmi-gated-feature mmi-is-locked">
				<?php
				if ( class_exists( 'MMI_Feature_Gate' ) ) {
					/* translators: %s: supplier name */
					MMI_Feature_Gate::render_locked( 'mmi-data-pipeline', sprintf( __( '%s Catalog', 'mmi-shared' ), static::supplier_label() ) );
				}
				?>
				<p class="mmi-process-section-description">
					<?php
					/* translators: %s: supplier name */
					echo esc_html( sprintf( __( 'The %s catalog reads the feed files MMI Data Pipeline fetches. Activate MMI Data Pipeline to browse it here.', 'mmi-shared' ), static::supplier_label() ) );
					?>
				</p>
			</div>
			<?php
		}

		/* ── Logs tab ────────────────────────────────────────────────────── */

		/**
		 * Last $limit lines of the supplier's log (optionally one level),
		 * newest first. Reads only the file's tail; MMI_Logger caps files at
		 * 10 MB.
		 *
		 * @return string[]
		 */
		public static function read_log_tail( int $limit, string $level = '' ): array {
			$dir  = function_exists( 'mmi_shared_lib_log_dir' ) ? trailingslashit( mmi_shared_lib_log_dir() ) : WP_CONTENT_DIR . '/mmi-logs/';
			$path = $dir . sanitize_file_name( static::log_category() ) . '.log';
			if ( ! is_readable( $path ) ) {
				return array();
			}
			$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handle ) {
				return array();
			}
			fseek( $handle, max( 0, (int) filesize( $path ) - static::LOG_TAIL_BYTES ) );
			$chunk = (string) stream_get_contents( $handle );
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

			$lines = array_filter( explode( "\n", $chunk ), static fn( $l ) => strpos( $l, '[' ) === 0 );
			if ( $level !== '' ) {
				$lines = array_filter( $lines, static fn( $l ) => strpos( $l, '[' . $level . ']' ) !== false );
			}
			return array_slice( array_reverse( array_values( $lines ) ), 0, $limit );
		}

		/** Read-only view of the supplier's log, newest first, filterable by level. */
		public static function render_tab_logs(): void {
			$levels = array( 'ERROR', 'WARN', 'INFO', 'DEBUG' );
			$level  = strtoupper( sanitize_key( wp_unslash( $_GET['level'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$level  = in_array( $level, $levels, true ) ? $level : '';
			$lines  = static::read_log_tail( 300, $level );
			$prefix = static::JS_PREFIX !== '' ? static::JS_PREFIX : static::supplier_id();
			?>
			<div class="mmi-process-section">
				<?php
				/* translators: %s: supplier name */
				static::section_head( 'media-text', sprintf( __( '%s process log', 'mmi-shared' ), static::supplier_label() ), static::log_description() );
				?>
				<div class="mmi-section-content">
					<form method="get" class="mmi-toolbar">
						<input type="hidden" name="page" value="<?php echo esc_attr( static::PAGE_SLUG ); ?>">
						<input type="hidden" name="tab" value="logs">
						<label>
							<?php esc_html_e( 'Level', 'mmi-shared' ); ?>
							<select name="level" id="mmi-<?php echo esc_attr( $prefix ); ?>-log-level">
								<option value=""><?php esc_html_e( 'All levels', 'mmi-shared' ); ?></option>
								<?php foreach ( $levels as $option ) : ?>
									<option value="<?php echo esc_attr( strtolower( $option ) ); ?>" <?php selected( $level, $option ); ?>><?php echo esc_html( $option ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
						<?php /* translators: %d: line count */ ?>
						<span><?php echo esc_html( sprintf( __( 'Last %d matching lines, newest first', 'mmi-shared' ), count( $lines ) ) ); ?></span>
					</form>

					<?php if ( ! $lines ) : ?>
						<p><?php esc_html_e( 'Nothing logged yet.', 'mmi-shared' ); ?></p>
					<?php else : ?>
						<div class="mmi-table-scroll-wrapper">
							<table class="mmi-table widefat striped">
								<tbody>
								<?php foreach ( $lines as $line ) : ?>
									<tr><td><code><?php echo esc_html( $line ); ?></code></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/* ── AJAX helpers ────────────────────────────────────────────────── */

		/** Nonce + capability for this page's AJAX actions; a refusal is audited. */
		protected static function guard(): void {
			check_ajax_referer( static::NONCE_ACTION, 'nonce' );
			if ( ! static::user_can() ) {
				if ( class_exists( 'MMI_Audit_Log' ) ) {
					MMI_Audit_Log::record( 'mmi-' . static::supplier_id() . '-integration', 'ajax.denied', array(
						'outcome' => 'denied',
						'details' => array( 'ajax_action' => sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ) ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					) );
				}
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mmi-shared' ) ), 403 );
			}
		}

		/** { ok, message, … } as a JSON success or error. */
		protected static function respond( array $result ): void {
			! empty( $result['ok'] ) ? wp_send_json_success( $result ) : wp_send_json_error( $result );
		}
	}
}
