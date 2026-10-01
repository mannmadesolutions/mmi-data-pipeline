<?php
/**
 * Centralized Logger utility for all MMI plugins (bundled/vendored copy).
 *
 * Same behavior as the original mmi-hub/includes/class-logger.php this was
 * vendored from, with one change: log directory resolution no longer depends
 * on the mmi-hub-specific MMI_HUB_LOG_DIR constant — it uses
 * mmi_shared_lib_log_dir() (bootstrap.php), which defaults to a suite-wide
 * wp-content/mmi-logs directory independent of any single plugin's own
 * folder. See ADR-0006. Do not hand-edit this file in a single plugin; edit
 * the canonical source and re-sync to every plugin that bundles it.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Logger {

	/* ── Constants ────── */

	const LOG_LEVEL_DEBUG = 0;
	const LOG_LEVEL_INFO  = 1;
	const LOG_LEVEL_WARN  = 2;
	const LOG_LEVEL_ERROR = 3;

	const LEVEL_NAMES = array(
		self::LOG_LEVEL_DEBUG => 'DEBUG',
		self::LOG_LEVEL_INFO  => 'INFO',
		self::LOG_LEVEL_WARN  => 'WARN',
		self::LOG_LEVEL_ERROR => 'ERROR',
	);

	const MAX_LOG_SIZE = 10485760; // 10MB
	const MAX_RETRIES  = 3;
	const RETRY_DELAY  = 100000; // microseconds

	/* ── Properties ────── */

	/**
	 * Names whose values are secrets. Shared by the message patterns
	 * (key=value, key: value, "key":"value", 'key' => 'value') and by
	 * context-array keys. Matched as a substring, so prefixed names like
	 * access_token, refresh_token, wc_consumer_secret are covered too.
	 */
	const SECRET_NAME_PATTERN = '(?:password|passwd|pwd|secret|client[_-]?secret|consumer[_-]?(?:key|secret)|private[_-]?key|api[_-]?key|apikey|license[_-]?key|token|authorization|cookie|signature|session[_-]?id)';

	/**
	 * Context keys matched exactly (too generic to substring-match):
	 * an HMAC 'sig', a bare 'auth', 'pass' or 'pin'.
	 */
	const SECRET_EXACT_KEYS = array( 'sig', 'auth', 'pass', 'pin' );

	const REDACTED = '[REDACTED]';

	private static $min_level = self::LOG_LEVEL_INFO;
	private static $enabled   = true;

	/* ── Public API ────── */

	public static function debug( $message, $context = array(), $category = 'general', $source = '' ) {
		self::log( self::LOG_LEVEL_DEBUG, $message, $context, $category, $source );
	}

	public static function info( $message, $context = array(), $category = 'general', $source = '' ) {
		self::log( self::LOG_LEVEL_INFO, $message, $context, $category, $source );
	}

	public static function warn( $message, $context = array(), $category = 'general', $source = '' ) {
		self::log( self::LOG_LEVEL_WARN, $message, $context, $category, $source );
	}

	public static function error( $message, $context = array(), $category = 'general', $source = '' ) {
		self::log( self::LOG_LEVEL_ERROR, $message, $context, $category, $source );
	}

	public static function get_min_level() {
		return self::$min_level;
	}

	public static function set_min_level( $level ) {
		self::$min_level = $level;
	}

	public static function set_enabled( $enabled ) {
		self::$enabled = (bool) $enabled;
	}

	public static function clear( $category = 'general' ) {
		if ( ! self::$enabled ) {
			return false;
		}

		$log_file = self::get_log_file( $category );
		if ( ! file_exists( $log_file ) ) {
			return true;
		}

		return @unlink( $log_file );
	}

	public static function get_log_file( $category = 'general' ) {
		$log_dir = self::get_log_dir();
		return trailingslashit( $log_dir ) . sanitize_file_name( $category ) . '.log';
	}

	/**
	 * Get log directory path — delegates to mmi_shared_lib_log_dir() so the
	 * path is derived the same way regardless of which plugin's bundled copy
	 * of this class actually won version negotiation.
	 *
	 * @return string Absolute path to log directory (no trailing slash).
	 */
	public static function get_log_dir() {
		return mmi_shared_lib_log_dir();
	}

	/* ── Private Methods ────── */

	private static function log( $level, $message, $context = array(), $category = 'general', $source = '' ) {
		if ( ! self::$enabled || $level < self::$min_level ) {
			return;
		}

		$entry = self::format_entry( $level, $message, $context, $source );

		self::write_log( $entry, $category );

		self::rotate_if_needed( $category );
	}

	private static function format_entry( $level, $message, $context = array(), $source = '' ) {
		$timestamp     = gmdate( 'Y-m-d H:i:s', time() );
		$microseconds  = explode( ' ', microtime() )[0];
		$timestamp    .= substr( $microseconds, 1 ); // Append decimal part (.XXXXXX)

		$level_name = self::LEVEL_NAMES[ $level ] ?? 'UNKNOWN';

		$entry = "[$timestamp] [$level_name]";

		if ( ! empty( $source ) ) {
			$entry .= " [$source]";
		}

		$entry .= ' ' . self::sanitize_message( $message );

		if ( ! empty( $context ) && is_array( $context ) ) {
			$context_str = self::format_context( $context );
			if ( ! empty( $context_str ) ) {
				$entry .= ' | ' . $context_str;
			}
		}

		return $entry . "\n";
	}

	private static function sanitize_message( $message ) {
		return wp_kses_post( self::redact_string( (string) $message ) );
	}

	/**
	 * Mask secret values inside free text: headers, query strings, JSON and
	 * PHP-array dumps, plus anything shaped like an MMI license key.
	 *
	 * @param  string $text
	 * @return string
	 */
	public static function redact_string( string $text ): string {
		if ( $text === '' ) {
			return $text;
		}
		$name = self::SECRET_NAME_PATTERN;
		$r    = self::REDACTED;

		$text = preg_replace(
			array(
				// Authorization: Bearer|Basic|Token <credential>
				'/(authorization["\']?\s*[:=]\s*["\']?(?:Bearer|Basic|Token|Digest)\s+)[^\s"\',;]+/i',
				// Cookie / Set-Cookie header line: everything to end of line.
				'/((?:set-)?cookie\s*:\s*)[^\r\n]+/i',
				// JSON: "access_token":"value" (escaped quotes inside the value handled).
				'/("[\w-]*' . $name . '[\w-]*"\s*:\s*")(?:[^"\\\\]|\\\\.)*(")/i',
				// JSON non-string: "secret": 12345 / true
				'/("[\w-]*' . $name . '[\w-]*"\s*:\s*)(?!["\[{\s])[^,}\]\s]+/i',
				// PHP var_export/print_r: 'password' => 'value'  /  [password] => value
				'/(\'[\w-]*' . $name . '[\w-]*\'\s*=>\s*\')(?:[^\'\\\\]|\\\\.)*(\')/i',
				'/(\[[\w-]*' . $name . '[\w-]*\]\s*=>\s*)[^\r\n]+/i',
				// key=value / key: value (query strings, form bodies, prose).
				'/(\b[\w-]*' . $name . '[\w-]*\s*[:=]\s*)(?!\[REDACTED\]|(?:Bearer|Basic|Token|Digest)\s|(?:true|false|null)\b)["\']?[^\s&,;"\'<>]+["\']?/i',
			),
			array(
				'$1' . $r,
				'$1' . $r,
				'$1' . $r . '$2',
				'$1"' . $r . '"',
				'$1' . $r . '$2',
				'$1' . $r,
				'$1' . $r,
			),
			$text
		) ?? $text;

		// MMI license keys anywhere in free text (current 4×5 and older 3×4
		// formats): keep the last group only.
		$text = preg_replace( '/\bMMI-[A-Z0-9]{4}(?:-[A-Z0-9]{5}){3}-([A-Z0-9]{5})\b/i', 'MMI-****-*****-*****-*****-$1', $text ) ?? $text;
		$text = preg_replace( '/\bMMI-[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{4}-([A-Z0-9]{4})\b/i', 'MMI-****-****-****-$1', $text ) ?? $text;

		return $text;
	}

	/**
	 * Recursively mask secret-named keys in a context array, then redact
	 * string leaves as free text (a value may itself be a JSON body or URL).
	 *
	 * @param  mixed $value
	 * @param  int   $depth
	 * @return mixed
	 */
	public static function redact_context( $value, int $depth = 0 ) {
		if ( is_object( $value ) ) {
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		if ( is_string( $value ) ) {
			return self::redact_string( $value );
		}
		if ( ! is_array( $value ) || $depth > 8 ) {
			return $value;
		}
		foreach ( $value as $k => $v ) {
			if ( is_string( $k ) && self::is_secret_key( $k ) && $v !== '' && $v !== null && ! is_bool( $v ) ) {
				$value[ $k ] = self::REDACTED;
				continue;
			}
			$value[ $k ] = self::redact_context( $v, $depth + 1 );
		}
		return $value;
	}

	private static function is_secret_key( string $key ): bool {
		return in_array( strtolower( $key ), self::SECRET_EXACT_KEYS, true )
			|| (bool) preg_match( '/' . self::SECRET_NAME_PATTERN . '/i', $key );
	}

	private static function format_context( $context ) {
		$parts = array();

		foreach ( (array) self::redact_context( (array) $context ) as $key => $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				$value = wp_json_encode( $value );
			} elseif ( is_bool( $value ) ) {
				$value = $value ? 'true' : 'false';
			} else {
				$value = (string) $value;
			}

			$parts[] = $key . '=' . $value;
		}

		// Second pass over the flattened text: catches a secret inside a value
		// that only becomes "key":"value"-shaped once JSON-encoded.
		return self::redact_string( implode( ', ', $parts ) );
	}

	private static function write_log( $entry, $category ) {
		$log_dir = self::get_log_dir();
		if ( ! is_dir( $log_dir ) ) {
			wp_mkdir_p( $log_dir );
			// Deny rule + index.php for Apache/LiteSpeed. nginx ignores
			// .htaccess — see mmi_shared_lib_private_dir() for why private
			// data belongs outside a guessable path.
			if ( function_exists( 'mmi_shared_lib_guard_dir' ) && is_dir( $log_dir ) ) {
				mmi_shared_lib_guard_dir( trailingslashit( $log_dir ) );
			}
		}

		$log_file = self::get_log_file( $category );
		$attempts = 0;

		while ( $attempts < self::MAX_RETRIES ) {
			$result = @file_put_contents(
				$log_file,
				$entry,
				FILE_APPEND | LOCK_EX
			);

			if ( false !== $result ) {
				return true;
			}

			$attempts++;
			usleep( self::RETRY_DELAY );
		}

		error_log( 'MMI_Logger: Failed to write to ' . $log_file );
		return false;
	}

	private static function rotate_if_needed( $category ) {
		$log_file = self::get_log_file( $category );

		if ( ! file_exists( $log_file ) ) {
			return;
		}

		$file_size = filesize( $log_file );
		if ( false === $file_size || $file_size < self::MAX_LOG_SIZE ) {
			return;
		}

		$keep_bytes = (int) ( self::MAX_LOG_SIZE / 2 );
		$handle     = @fopen( $log_file, 'r' );
		if ( ! $handle ) {
			return;
		}

		@fseek( $handle, -$keep_bytes, SEEK_END );
		@fgets( $handle ); // discard partial first line
		$content = stream_get_contents( $handle );
		@fclose( $handle );

		if ( false === $content || '' === $content ) {
			return;
		}

		$notice = '--- [LOG TRUNCATED ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC — entries older than this point removed to stay within 10 MB limit] ---' . "\n";
		@file_put_contents( $log_file, $notice . $content, LOCK_EX );
	}
}
