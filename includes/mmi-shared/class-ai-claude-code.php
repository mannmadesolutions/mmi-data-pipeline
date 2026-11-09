<?php
/**
 * MMI_AI_Claude_Code — AI writing on a Claude subscription (Pro or Max)
 * instead of an API key: the Claude Code CLI, installed and signed in on the
 * site's own server, run headless for one answer at a time. Used by MMI_AI
 * when the Anthropic provider is set to connect by plan.
 *
 * The plugin never signs anyone in and never sees a token: the person signs
 * the CLI in themselves (`claude auth login`, as the user the site runs as),
 * and this class only asks the CLI how it is signed in. Only a subscription
 * sign-in (authMethod claude.ai) is used; a Console sign-in bills API usage,
 * so it is refused with a pointer to the API-key providers instead.
 *
 * Each call: prompt on stdin as stream-json (so pictures can go along), no
 * tools, no MCP, no session files, a clean env with no provider key and an
 * empty working directory, thinking off (MAX_THINKING_TOKENS=0), and an
 * answer that must match a JSON schema. With thinking on, Haiku spent up to
 * 12,000 tokens and 79 s on one title, past the web server's 60 s limit.
 * Calls count against the plan's usage limits. If the CLI reports that a call
 * used the account's paid extra usage, plan calls stop for a day
 * (claude_code_paused) and the AI settings say why.
 *
 * A site can hand the call to its own runner through the
 * `mmi_ai_claude_code_run` filter (return the CLI's result object, or
 * ['error' => …]). Arguments: null, $system, $schema, $content, $model,
 * $timeout, $thinking (false: turn thinking off; true from 1.49.0 when a
 * caller asks for it through MMI_AI::ask()), $purpose (1.49.0: who asked, for
 * the site's own call log). The filter's name before shared library 1.48.0,
 * `mmi_seo_claude_code_run`, is still applied.
 *
 * Always one item at a time and only with a person there.
 *
 * Lifted from mmi-seo's MMI_SEO\Claude_Code (shared library 1.48.0).
 * Do not hand-edit this file in a single plugin; edit the canonical source in
 * mmi-admin/lib/mmi-shared/ and run lib/sync-to-plugins.sh.
 *
 * @package MMI_Shared
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_AI_Claude_Code' ) ) :

	class MMI_AI_Claude_Code {

		/**
		 * Seconds a call may take. A call runs inside a web request, and web servers
		 * commonly give up at 60 s, after which the page shows only a generic error
		 * and the answer is lost. Stopping at 45 s leaves room to say what happened.
		 * With thinking off a call takes 2-5 s.
		 */
		const TIMEOUT    = 45;
		const STATUS_KEY = 'mmi_ai_claude_code_status';
		const STATUS_TTL = 600;
		const OPT_PAUSED = 'claude_code_paused'; // MMI_AI setting: unix time a call used extra usage
		const OPT_MODEL  = 'claude_code_model';
		const PAUSE_S    = DAY_IN_SECONDS;
		const RUN_FILTER        = 'mmi_ai_claude_code_run';
		const LEGACY_RUN_FILTER = 'mmi_seo_claude_code_run';

		/** Model aliases the CLI takes; the CLI maps each to the newest model of that size. */
		const MODELS = array(
			'haiku'  => 'Haiku',
			'sonnet' => 'Sonnet',
			'opus'   => 'Opus',
		);
		const DEFAULT_MODEL = 'haiku';

		/** Home directory of the user PHP runs as (where the CLI keeps its sign-in). */
		public static function home(): string {
			$home = (string) getenv( 'HOME' );
			if ( $home === '' && function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
				$user = posix_getpwuid( posix_geteuid() );
				$home = is_array( $user ) ? (string) ( $user['dir'] ?? '' ) : '';
			}
			return untrailingslashit( $home );
		}

		/** The user PHP runs as, to name in the sign-in steps. */
		public static function os_user(): string {
			if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
				$user = posix_getpwuid( posix_geteuid() );
				if ( is_array( $user ) && ! empty( $user['name'] ) ) {
					return (string) $user['name'];
				}
			}
			return (string) get_current_user();
		}

		/** Path to the CLI ('' when it isn't installed where PHP can run it). */
		public static function bin(): string {
			return self::status()['bin'];
		}

		/**
		 * The first install that answers. open_basedir often hides the home
		 * folder from PHP's file checks while still letting it start programs,
		 * so a path PHP can't see is asked for its version instead.
		 */
		private static function find_bin(): string {
			$candidates = array( self::home() . '/.local/bin/claude', '/usr/local/bin/claude', '/usr/bin/claude' );
			$candidates = apply_filters( 'mmi_seo_claude_code_bin', $candidates ); // name before 1.48.0
			foreach ( (array) apply_filters( 'mmi_ai_claude_code_bin', $candidates ) as $path ) {
				if ( ! is_string( $path ) || $path === '' ) {
					continue;
				}
				if ( @is_executable( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir warns for paths outside it
					return $path;
				}
				if ( ini_get( 'open_basedir' ) && strpos( self::run_quick( $path, array( '--version' ) ), 'Claude Code' ) !== false ) {
					return $path;
				}
			}
			return '';
		}

		/** Whether this server lets PHP start a program at all. */
		public static function can_exec(): bool {
			if ( ! function_exists( 'proc_open' ) ) {
				return false;
			}
			$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
			return ! in_array( 'proc_open', $disabled, true );
		}

		/**
		 * How the CLI is set up here, asked of `claude auth status` (no model call,
		 * well under a second) and kept STATUS_TTL.
		 *
		 * @return array{exec:bool, bin:string, version:string, logged_in:bool, method:string, plan:string, email:string}
		 */
		public static function status( bool $fresh = false ): array {
			static $status = null;
			if ( $status !== null && ! $fresh ) {
				return $status;
			}
			$cached = $fresh ? false : get_transient( self::STATUS_KEY );
			if ( is_array( $cached ) ) {
				$status = $cached;
				return $status;
			}
			$status = array( 'exec' => self::can_exec(), 'bin' => '', 'version' => '', 'logged_in' => false, 'method' => '', 'plan' => '', 'email' => '' );
			if ( $status['exec'] ) {
				$status['bin'] = self::find_bin();
			}
			if ( $status['bin'] !== '' ) {
				$status['version'] = trim( (string) preg_replace( '/\s*\(.*$/', '', self::run_quick( $status['bin'], array( '--version' ) ) ) );
				$auth              = json_decode( self::run_quick( $status['bin'], array( 'auth', 'status', '--json' ) ), true );
				if ( is_array( $auth ) ) {
					$status['logged_in'] = ! empty( $auth['loggedIn'] );
					$status['method']    = (string) ( $auth['authMethod'] ?? '' );
					$status['plan']      = (string) ( $auth['subscriptionType'] ?? '' );
					$status['email']     = sanitize_email( (string) ( $auth['email'] ?? '' ) );
				}
			}
			set_transient( self::STATUS_KEY, $status, self::STATUS_TTL );
			return $status;
		}

		/** Forget the cached sign-in status (after a sign-in problem, or a Check button). */
		public static function forget_status(): void {
			delete_transient( self::STATUS_KEY );
		}

		/** Signed in with a subscription, so a call can run. */
		public static function ready(): bool {
			$s = self::status();
			return $s['bin'] !== '' && $s['logged_in'] && $s['method'] === 'claude.ai';
		}

		/** Whether a site runner (the filter) takes the calls instead of the CLI run here. */
		public static function site_runner(): bool {
			return has_filter( self::RUN_FILTER ) || has_filter( self::LEGACY_RUN_FILTER );
		}

		/** Unix time plan calls were paused for using extra usage (0 when not paused). */
		public static function paused_at(): int {
			$at = (int) MMI_AI::get( self::OPT_PAUSED, 0 );
			return $at && time() - $at < self::PAUSE_S ? $at : 0;
		}

		public static function model(): string {
			$model = (string) MMI_AI::get( self::OPT_MODEL, '' );
			return isset( self::MODELS[ $model ] ) ? $model : self::DEFAULT_MODEL;
		}

		/**
		 * One answer matching $schema.
		 *
		 * @param array      $schema JSON schema of the answer (an object).
		 * @param array|null $image  { mime, data (base64) }
		 * @return array|WP_Error { answer: array, input: int, output: int }
		 */
		public static function complete( string $system, string $user, array $schema, ?array $image = null ) {
			$content = array();
			if ( $image ) {
				$content[] = array( 'type' => 'image', 'source' => array( 'type' => 'base64', 'media_type' => $image['mime'], 'data' => $image['data'] ) );
			}
			$content[] = array( 'type' => 'text', 'text' => $user );
			return self::complete_content( $system, $content, $schema );
		}

		/**
		 * One answer matching $schema, from Messages API content blocks (text, and
		 * base64 images), with the caller's model, time and thinking (1.49.0).
		 *
		 * @param string $model    A model the CLI takes (alias or id); '' = the AI settings' plan model.
		 * @param int    $timeout  Seconds; 0 = TIMEOUT (web requests). Longer only where nothing waits on it (WP-CLI).
		 * @param bool   $thinking Leave the model's thinking on (it costs time and plan usage).
		 * @return array|WP_Error { answer: array, input: int, output: int }
		 */
		public static function complete_content( string $system, array $content, array $schema, string $model = '', int $timeout = 0, bool $thinking = false, string $purpose = '' ) {
			$model   = $model !== '' ? $model : self::model();
			$timeout = $timeout > 0 ? $timeout : self::TIMEOUT;
			if ( self::paused_at() ) {
				return new WP_Error(
					'mmi_ai_paused',
					sprintf(
						/* translators: %s: date and time */
						__( 'A Claude call on %s was billed as extra usage, so writing on the plan is paused for a day. Turn extra usage off for the Claude account, or use an API key until then.', 'mmi-shared' ),
						wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), self::paused_at() )
					),
					array( 'stop' => true )
				);
			}
			$start = microtime( true );
			$out   = apply_filters( self::RUN_FILTER, null, $system, $schema, $content, $model, $timeout, $thinking, $purpose );
			if ( ! is_array( $out ) ) {
				$out = apply_filters( self::LEGACY_RUN_FILTER, null, $system, $schema, $content, $model, $timeout, $thinking );
			}
			if ( ! is_array( $out ) ) {
				if ( ! self::ready() ) {
					/* translators: %s: where AI is set up */
					return new WP_Error( 'mmi_ai_off', sprintf( __( 'Claude Code on this server isn\'t signed in with a Claude Pro or Max plan. Open %s for the steps.', 'mmi-shared' ), MMI_AI::where() ), array( 'stop' => true ) );
				}
				$out = self::run( $system, $schema, $content, $model, $timeout, $thinking );
			}
			if ( isset( $out['error'] ) && microtime( true ) - $start >= $timeout - 2 ) {
				return new WP_Error(
					'mmi_ai_timeout',
					sprintf(
						/* translators: %d: seconds */
						__( 'Claude didn\'t answer within %d seconds, so the call was stopped. Nothing was changed. Try again.', 'mmi-shared' ),
						$timeout
					)
				);
			}
			if ( isset( $out['error'] ) ) {
				return new WP_Error( 'mmi_ai_cli', wp_strip_all_tags( (string) $out['error'] ) );
			}
			$answer = $out['structured_output'] ?? null;
			if ( ! is_array( $answer ) ) {
				return new WP_Error( 'mmi_ai_format', __( 'Claude\'s answer couldn\'t be read. Try again.', 'mmi-shared' ) );
			}
			$usage = (array) ( $out['usage'] ?? array() );
			return array(
				'answer' => $answer,
				'input'  => (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['cache_read_input_tokens'] ?? 0 ) + (int) ( $usage['cache_creation_input_tokens'] ?? 0 ),
				'output' => (int) ( $usage['output_tokens'] ?? 0 ),
			);
		}

		/** One headless CLI run. @return array the CLI's result object, or ['error' => …] */
		private static function run( string $system, array $schema, array $content, string $model, int $timeout, bool $thinking ): array {
			$cwd = trailingslashit( get_temp_dir() ) . 'mmi-ai-claude-' . wp_generate_password( 8, false, false );
			if ( ! wp_mkdir_p( $cwd ) ) {
				return array( 'error' => __( 'No temporary folder could be made for the Claude call.', 'mmi-shared' ) );
			}
			$cmd = array(
				self::bin(), '-p',
				'--system-prompt', $system,
				'--model', $model,
				'--tools', '',
				'--strict-mcp-config',
				'--no-session-persistence',
				'--json-schema', (string) wp_json_encode( $schema ),
				'--input-format', 'stream-json',
				'--output-format', 'stream-json',
				'--verbose',
			);
			$stdin = wp_json_encode( array( 'type' => 'user', 'message' => array( 'role' => 'user', 'content' => $content ) ) ) . "\n";
			$r     = self::proc( $cmd, $stdin, $cwd, $timeout, $thinking );
			@rmdir( $cwd ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

			$out = null;
			foreach ( explode( "\n", $r['stdout'] ) as $line ) {
				$d = json_decode( $line, true );
				if ( ! is_array( $d ) ) {
					continue;
				}
				if ( ( $d['type'] ?? '' ) === 'rate_limit_event' && ! empty( $d['rate_limit_info']['isUsingOverage'] ) ) {
					MMI_AI::set( self::OPT_PAUSED, time() );
					MMI_AI::log( 'warn', '[AI] A Claude subscription call used extra usage; subscription calls paused for a day.' );
				}
				if ( ( $d['type'] ?? '' ) === 'result' ) {
					$out = $d;
				}
			}
			if ( ! is_array( $out ) ) {
				$why = trim( $r['stderr'] !== '' ? $r['stderr'] : $r['stdout'] );
				/* translators: 1: exit code, 2: what the CLI said */
				return array( 'error' => sprintf( __( 'Claude Code stopped (exit %1$d): %2$s', 'mmi-shared' ), $r['rc'], mb_substr( $why !== '' ? $why : __( 'no answer', 'mmi-shared' ), 0, 300 ) ) );
			}
			if ( ! empty( $out['is_error'] ) ) {
				self::forget_status(); // a sign-in problem shows on the AI settings at once
				/* translators: %s: what the CLI said */
				return array( 'error' => sprintf( __( 'Claude Code: %s', 'mmi-shared' ), mb_substr( (string) ( $out['result'] ?? __( 'no answer', 'mmi-shared' ) ), 0, 300 ) ) );
			}
			return $out;
		}

		/** stdout of a short CLI command (version, auth status). */
		private static function run_quick( string $bin, array $args ): string {
			return self::proc( array_merge( array( $bin ), $args ), '', get_temp_dir(), 20 )['stdout'];
		}

		/**
		 * Runs a command with a clean env: no ANTHROPIC_API_KEY or any other
		 * provider variable can reach the CLI, so it can only use its own sign-in.
		 * Stopped after $timeout seconds; both pipes are read as they fill.
		 *
		 * @return array{stdout:string, stderr:string, rc:int}
		 */
		private static function proc( array $cmd, string $stdin, string $cwd, int $timeout, bool $thinking = false ): array {
			$env = array( 'HOME' => self::home(), 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'LANG' => 'C.UTF-8' );
			if ( ! $thinking ) {
				$env['MAX_THINKING_TOKENS'] = '0';
			}
			$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, $cwd, $env );
			if ( ! is_resource( $proc ) ) {
				return array( 'stdout' => '', 'stderr' => 'proc_open failed', 'rc' => -1 );
			}
			fwrite( $pipes[0], $stdin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $pipes[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );
			$out      = array( 1 => '', 2 => '' );
			$deadline = microtime( true ) + $timeout;
			$killed   = false;
			while ( ! feof( $pipes[1] ) || ! feof( $pipes[2] ) ) {
				if ( microtime( true ) > $deadline ) {
					proc_terminate( $proc, 9 );
					$killed = true;
					break;
				}
				$read   = array_filter( array( 1 => $pipes[1], 2 => $pipes[2] ), static fn( $p ) => ! feof( $p ) );
				$write  = null;
				$except = null;
				if ( ! $read || stream_select( $read, $write, $except, 1 ) === false ) {
					break;
				}
				foreach ( $read as $pipe ) {
					$out[ $pipe === $pipes[1] ? 1 : 2 ] .= (string) fread( $pipe, 65536 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
			fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$rc = proc_close( $proc );
			if ( $killed ) {
				/* translators: %d: seconds */
				$out[2] = sprintf( __( 'no answer within %d seconds', 'mmi-shared' ), $timeout );
			}
			return array( 'stdout' => $out[1], 'stderr' => $out[2], 'rc' => $rc );
		}
	}

endif;
