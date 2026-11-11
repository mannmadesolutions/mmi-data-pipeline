<?php
/**
 * MMI_Access_Log — finds and reads this site's web server access log, for
 * any MMI plugin that learns from it (mmi-seo's 404 shapes and Bing errors,
 * Google Services' scraper networks, Server Monitor's visitors).
 *
 * Why a shared class: each plugin used to find the log with glob() and
 * fopen(). On RunCloud and many shared hosts, web PHP runs under
 * open_basedir, which keeps it inside the site folder, so those calls fail
 * in the dashboard and in WP-Cron while the same code works from WP-CLI
 * (which has no open_basedir). Server Monitor 2.11.0 found this; a shell
 * command isn't bound by open_basedir, so a log PHP can't open can still be
 * read with `tail` when the host allows shell_exec.
 *
 * - Candidates by host convention (RunCloud, cPanel, Plesk) plus the
 *   `mmi_access_logs` filter (paths or glob patterns). The first pattern that
 *   finds a readable log wins: a front proxy (nginx) and the server behind
 *   it (Apache) log the same request twice.
 * - `read()` streams lines from a byte offset, so a caller can keep a cursor
 *   and read only what was added since its last run (a 70 MB log is then a
 *   few hundred KB an hour). Rotated `.gz` files are read whole.
 * - How the log can be read is decided in a web request, never from WP-CLI:
 *   `web_access()` is what the dashboard and WP-Cron can do. `note_web()`
 *   records it; callers call it from admin page loads and cron runs.
 *
 * Reading only. Nothing here writes to the log or sends it anywhere.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

if ( class_exists( 'MMI_Access_Log' ) ) {
	return;
}

class MMI_Access_Log {

	/** Settings key: what a web request could read, saved only from web requests. */
	const WEB_KEY = 'mmi_access_log_web';

	/** Re-detect at most this often from web requests. */
	const RECHECK = HOUR_IN_SECONDS;

	/** Longest line read; longer lines are cut (a bot packing JSON into a query string). */
	const LINE_MAX = 16384;

	/**
	 * Combined log format request line: [time] "METHOD path PROTO" status bytes "referrer" "agent".
	 * 1 time, 2 method, 3 path, 4 status, 5 referrer, 6 agent.
	 */
	const LINE = '#\[([^\]]+)\] "([A-Z]+) (\S+) [^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"#';

	/** Log path patterns for this site, most specific first. */
	public static function patterns(): array {
		$abspath  = defined( 'ABSPATH' ) ? ABSPATH : '';
		$host     = function_exists( 'home_url' ) ? strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) : '';
		$patterns = array();
		if ( preg_match( '#^/home/([^/]+)/webapps/([^/]+)/#', $abspath, $m ) ) { // RunCloud
			$patterns[] = "/home/{$m[1]}/logs/nginx/{$m[2]}_access.log*";
			$patterns[] = "/home/{$m[1]}/logs/apache2/{$m[2]}_access.log*";
		}
		if ( $host !== '' && preg_match( '#^(/home\d*/[^/]+)/#', $abspath, $m ) ) { // cPanel
			$patterns[] = "{$m[1]}/access-logs/{$host}*";
		}
		if ( $host !== '' ) { // Plesk
			$patterns[] = "/var/www/vhosts/system/{$host}/logs/access*log*";
		}
		/**
		 * Where this site's access logs are: paths or glob patterns, tried in order.
		 *
		 * @param string[] $patterns
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'mmi_access_logs', $patterns ) ) ) );
	}

	/**
	 * Readable logs, oldest first, each with how it can be read.
	 *
	 * @param string[] $extra Patterns a caller adds ahead of the defaults (its own older filter).
	 * @return array<string, array{mtime:int, size:int, via:string}> path => facts; via is 'php' or 'shell'.
	 */
	public static function files( array $extra = array() ): array {
		foreach ( array_merge( $extra, self::patterns() ) as $pattern ) {
			$found = self::match( (string) $pattern );
			if ( $found ) {
				uasort( $found, static fn( $a, $b ) => $a['mtime'] <=> $b['mtime'] );
				return $found;
			}
		}
		return array();
	}

	/** The newest readable log (the one being written), or ''. */
	public static function current( array $extra = array() ): string {
		$files = self::files( $extra );
		foreach ( array_reverse( $files, true ) as $path => $facts ) {
			if ( substr( $path, -3 ) !== '.gz' ) {
				return $path;
			}
		}
		return '';
	}

	/** @return array<string, array{mtime:int, size:int, via:string}> */
	private static function match( string $pattern ): array {
		$found = array();
		foreach ( (array) @glob( $pattern ) as $file ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir
			if ( is_string( $file ) && @is_file( $file ) && @is_readable( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$found[ $file ] = array( 'mtime' => (int) @filemtime( $file ), 'size' => (int) @filesize( $file ), 'via' => 'php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		if ( $found || ! self::outside_basedir( $pattern ) || ! self::can_shell() ) {
			return $found;
		}
		// PHP may not look there; a shell command may. The pattern is ours or a filter's,
		// so only its directory is quoted and the glob part is matched by the shell.
		$dir  = dirname( $pattern );
		$glob = basename( $pattern );
		if ( preg_match( '/[^A-Za-z0-9._*?\[\]-]/', str_replace( '_', '', $glob ) ) ) {
			return $found;
		}
		$cmd = 'cd ' . escapeshellarg( $dir ) . ' 2>/dev/null && for f in ' . $glob . '; do'
			. ' if [ -f "$f" ] && [ -r "$f" ]; then printf \'%s|%s|%s\n\' "$f" "$(stat -c %Y "$f" 2>/dev/null)" "$(stat -c %s "$f" 2>/dev/null)"; fi; done';
		$out = @shell_exec( $cmd ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		foreach ( is_string( $out ) ? explode( "\n", trim( $out ) ) : array() as $row ) {
			$parts = explode( '|', $row );
			if ( count( $parts ) === 3 && $parts[0] !== '' && strpos( $parts[0], '/' ) === false ) {
				$found[ $dir . '/' . $parts[0] ] = array( 'mtime' => (int) $parts[1], 'size' => (int) $parts[2], 'via' => 'shell' );
			}
		}
		return $found;
	}

	/** A file's identity, so a cursor can tell when the log was rotated. */
	public static function inode( string $path, string $via ): int {
		if ( $via === 'php' ) {
			return (int) @fileinode( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$out = self::can_shell() ? @shell_exec( 'stat -c %i ' . escapeshellarg( $path ) . ' 2>/dev/null' ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		return (int) trim( (string) $out );
	}

	/**
	 * Calls $each( string $line ) for every whole line from byte $from to the end, or until
	 * $budget seconds have passed or $max_bytes were read.
	 *
	 * @return array{read:int, lines:int, offset:int, partial:bool, error:string}
	 *         offset: where to start next time (just past the last whole line handled).
	 */
	public static function read( string $path, string $via, int $from, callable $each, float $budget = 20.0, int $max_bytes = 0 ): array {
		$start  = microtime( true );
		$gz     = substr( $path, -3 ) === '.gz';
		$from   = $gz ? 0 : max( 0, $from );
		$result = array( 'read' => 0, 'lines' => 0, 'offset' => $from, 'partial' => false, 'error' => '' );
		$source = self::open( $path, $via, $from );
		if ( ! $source ) {
			$result['error'] = 'unreadable';
			return $result;
		}
		$offset = $from; // just past the last whole line handled
		$pos    = $from; // bytes fetched so far (uncompressed for .gz)
		$carry  = '';
		while ( true ) {
			$chunk = self::next_chunk( $source, $pos );
			if ( $chunk === '' ) {
				break;
			}
			$pos  += strlen( $chunk );
			$lines = explode( "\n", $carry . $chunk );
			$carry = (string) array_pop( $lines ); // the part line at the end: read it next time round
			foreach ( $lines as $line ) {
				$offset += strlen( $line ) + 1;
				if ( strlen( $line ) > self::LINE_MAX ) {
					continue; // a bot packing JSON into a query string
				}
				++$result['lines'];
				$each( $line . "\n" );
				if ( $result['lines'] % 5000 === 0 && ( microtime( true ) - $start > $budget || ( $max_bytes && $offset - $from > $max_bytes ) ) ) {
					$result['partial'] = true;
					break 2;
				}
			}
			if ( strlen( $carry ) > MB_IN_BYTES ) {
				$offset += strlen( $carry ); // no line is this long: skip it
				$carry   = '';
			}
			if ( strlen( $chunk ) < $source['chunk'] && ! $source['stream'] ) {
				break; // end of file
			}
		}
		self::close( $source );
		$result['read']   = $offset - $from;
		$result['offset'] = $offset;
		return $result;
	}

	/**
	 * A way to fetch the file in chunks: a PHP handle, a pipe, or (where popen is disabled,
	 * as on RunCloud's web pool) one shell_exec per chunk.
	 *
	 * @return array{mode:string, h:mixed, path:string, gz:bool, chunk:int, stream:bool}|null
	 */
	private static function open( string $path, string $via, int $from ) {
		$gz = substr( $path, -3 ) === '.gz';
		if ( $via === 'php' ) {
			$h = $gz ? @gzopen( $path, 'rb' ) : @fopen( $path, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			if ( ! $h ) {
				return null;
			}
			if ( ! $gz && $from > 0 ) {
				fseek( $h, $from );
			}
			return array( 'mode' => $gz ? 'gz' : 'file', 'h' => $h, 'path' => $path, 'gz' => $gz, 'chunk' => MB_IN_BYTES, 'stream' => true );
		}
		if ( ! self::can_shell() ) {
			return null;
		}
		if ( self::can( 'popen' ) ) {
			$cmd = $gz
				? 'gzip -dc ' . escapeshellarg( $path ) . ' 2>/dev/null'
				: 'tail -c +' . ( $from + 1 ) . ' ' . escapeshellarg( $path ) . ' 2>/dev/null';
			$h = @popen( $cmd, 'r' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
			if ( $h ) {
				return array( 'mode' => 'pipe', 'h' => $h, 'path' => $path, 'gz' => $gz, 'chunk' => MB_IN_BYTES, 'stream' => true );
			}
		}
		return array( 'mode' => 'exec', 'h' => null, 'path' => $path, 'gz' => $gz, 'chunk' => 4 * MB_IN_BYTES, 'stream' => false );
	}

	/** The next chunk from $pos; '' at the end. */
	private static function next_chunk( array $source, int $pos ): string {
		switch ( $source['mode'] ) {
			case 'file':
			case 'pipe':
				$out = '';
				while ( strlen( $out ) < $source['chunk'] && ! feof( $source['h'] ) ) {
					$part = fread( $source['h'], $source['chunk'] - strlen( $out ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					if ( $part === false || $part === '' ) {
						break;
					}
					$out .= $part;
				}
				return $out;
			case 'gz':
				$out = gzread( $source['h'], $source['chunk'] );
				return is_string( $out ) ? $out : '';
		}
		// exec: a .gz is decompressed again for each chunk, so rotated archives are slow this way.
		$cat = $source['gz'] ? 'gzip -dc ' . escapeshellarg( $source['path'] ) . ' 2>/dev/null | tail -c +' . ( $pos + 1 ) : 'tail -c +' . ( $pos + 1 ) . ' ' . escapeshellarg( $source['path'] ) . ' 2>/dev/null';
		$out = @shell_exec( $cat . ' | head -c ' . (int) $source['chunk'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.PHP.DiscouragedPHPFunctions
		return is_string( $out ) ? $out : '';
	}

	private static function close( array $source ): void {
		if ( $source['mode'] === 'pipe' ) {
			pclose( $source['h'] );
		} elseif ( $source['mode'] === 'gz' ) {
			gzclose( $source['h'] );
		} elseif ( $source['mode'] === 'file' ) {
			fclose( $source['h'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * One log line's parts, or null when it isn't combined log format.
	 *
	 * @return array{time:int, method:string, path:string, code:int, referrer:string, agent:string}|null
	 */
	public static function parse( string $line ): ?array {
		if ( ! preg_match( self::LINE, $line, $m ) ) {
			return null;
		}
		$date = \DateTime::createFromFormat( 'd/M/Y:H:i:s O', $m[1] );
		return array(
			'time'     => $date ? $date->getTimestamp() : 0,
			'method'   => $m[2],
			'path'     => $m[3],
			'code'     => (int) $m[4],
			'referrer' => $m[5],
			'agent'    => $m[6],
		);
	}

	/**
	 * A user agent's family: the crawler's own name ("ElevenlabsCrawler", "Googlebot"),
	 * "Browser" for an ordinary browser, or "Other".
	 */
	public static function agent_family( string $ua ): string {
		// "indexer" and "externalhit": Meta's meta-webindexer and facebookexternalhit carry neither "bot" nor
		// "agent", and were counted as browsers (2026-11-11).
		if ( preg_match( '/([A-Za-z][A-Za-z0-9._-]*(?:bot|crawler|spider|fetcher|scraper|agent|indexer|externalhit)[A-Za-z0-9._-]*)/i', $ua, $m ) ) {
			$name = rtrim( $m[1], '/._-' );
			if ( strcasecmp( $name, 'user-agent' ) !== 0 ) {
				return substr( $name, 0, 40 );
			}
		}
		foreach ( array( 'curl', 'Wget', 'python-requests', 'Go-http-client', 'okhttp', 'axios', 'node-fetch', 'Java', 'libwww-perl', 'Scrapy', 'HeadlessChrome' ) as $tool ) {
			if ( stripos( $ua, $tool ) !== false ) {
				return $tool;
			}
		}
		if ( strpos( $ua, 'Mozilla/' ) === 0 ) {
			return 'Browser';
		}
		return 'Other';
	}

	/* ── What a web request can read ─────────────────────────────────── */

	/**
	 * What the dashboard and WP-Cron can read, as last seen from a web request:
	 * { via: 'php'|'shell'|'none', files: int, path: string, checked: int }.
	 * Unknown (never noted from the web) gives via 'unknown'.
	 */
	public static function web_access(): array {
		$state = class_exists( 'MMI_Settings' ) ? MMI_Settings::get( self::WEB_KEY, array() ) : array();
		if ( ! is_array( $state ) || empty( $state['via'] ) ) {
			return array( 'via' => 'unknown', 'files' => 0, 'path' => '', 'checked' => 0 );
		}
		return $state;
	}

	/**
	 * Records what this request can read. Does nothing from WP-CLI (no open_basedir there,
	 * so its answer says nothing about the dashboard), and at most once per RECHECK.
	 */
	public static function note_web( bool $force = false ): array {
		$state = self::web_access();
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $state;
		}
		if ( ! $force && $state['via'] !== 'unknown' && time() - (int) $state['checked'] < self::RECHECK ) {
			return $state;
		}
		$files   = self::files();
		$current = '';
		$via     = 'none';
		foreach ( array_reverse( $files, true ) as $path => $facts ) {
			if ( substr( $path, -3 ) !== '.gz' ) {
				$current = $path;
				$via     = $facts['via'];
				break;
			}
		}
		$state = array( 'via' => $via, 'files' => count( $files ), 'path' => $current, 'checked' => time() );
		if ( class_exists( 'MMI_Settings' ) ) {
			MMI_Settings::set( self::WEB_KEY, $state );
		}
		return $state;
	}

	/** Whether open_basedir keeps PHP away from $path. */
	public static function outside_basedir( string $path ): bool {
		$basedir = (string) ini_get( 'open_basedir' );
		if ( $basedir === '' ) {
			return false;
		}
		foreach ( explode( PATH_SEPARATOR, $basedir ) as $allowed ) {
			$allowed = rtrim( $allowed, '/' );
			if ( $allowed !== '' && ( $path === $allowed || strpos( $path, $allowed . '/' ) === 0 ) ) {
				return false;
			}
		}
		return true;
	}

	public static function can_shell(): bool {
		return self::can( 'shell_exec' ) && function_exists( 'escapeshellarg' );
	}

	private static function can( string $fn ): bool {
		if ( ! function_exists( $fn ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( $fn, $disabled, true );
	}
}
