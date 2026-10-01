<?php
/**
 * MMI_Resource_Guard — centralized server-load/memory gate (bundled/vendored copy).
 *
 * Identical to the original mmi-hub/includes/class-resource-guard.php this was
 * vendored from. Already fully self-contained (reads /proc directly, only
 * optionally touches MMI_Logger behind its own class_exists() guard) — no
 * behavioral change needed to vendor it, unlike MMI_Logger's log-dir
 * dependency. See ADR-0006. Do not hand-edit this file in a single plugin;
 * edit the canonical source and re-sync to every plugin that bundles it.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Resource_Guard {

	const DEFAULT_MAX_LOAD        = 2.5;
	const DEFAULT_MAX_MEMORY_PCT  = 90.0;
	const DEFAULT_POLL_INTERVAL_S = 3;
	const DEFAULT_TIMEOUT_S       = 60;

	/* ── Public API ──────────────────────────────────────────────────────── */

	public static function can_proceed( array $opts = array() ): bool {
		$max_load       = (float) ( $opts['max_load'] ?? self::DEFAULT_MAX_LOAD );
		$max_memory_pct = (float) ( $opts['max_memory_pct'] ?? self::DEFAULT_MAX_MEMORY_PCT );
		$name           = $opts['name'] ?? 'unnamed process';

		$load = self::get_load_average();
		if ( $load !== null && $load > $max_load ) {
			self::log( "⏸️ {$name} — blocked, load average {$load} exceeds {$max_load}" );
			return false;
		}

		$mem_pct = self::get_memory_percent();
		if ( $mem_pct !== null && $mem_pct > $max_memory_pct ) {
			self::log( "⏸️ {$name} — blocked, system memory {$mem_pct}% exceeds {$max_memory_pct}%" );
			return false;
		}

		if ( isset( $opts['min_php_headroom_mb'] ) && ! self::has_php_headroom( (int) $opts['min_php_headroom_mb'] ) ) {
			self::log( "⏸️ {$name} — blocked, PHP memory_limit headroom below {$opts['min_php_headroom_mb']}MB" );
			return false;
		}

		return true;
	}

	public static function wait_for_safe_load( string $key, array $opts = array() ): bool {
		$poll_interval = max( 1, (int) ( $opts['poll_interval'] ?? self::DEFAULT_POLL_INTERVAL_S ) );
		$timeout       = (int) ( $opts['timeout'] ?? self::DEFAULT_TIMEOUT_S );

		if ( self::can_proceed( $opts + array( 'name' => $key ) ) ) {
			return true;
		}

		if ( $timeout <= 0 ) {
			return false;
		}

		$deadline = time() + $timeout;
		while ( time() < $deadline ) {
			sleep( $poll_interval );
			if ( self::can_proceed( $opts + array( 'name' => $key ) ) ) {
				return true;
			}
		}

		self::log( "⛔ {$key} — timed out after {$timeout}s waiting for safe load" );
		return false;
	}

	/* ── Raw metrics (also usable directly for admin-dashboard display) ────── */

	public static function get_load_average(): ?float {
		if ( function_exists( 'sys_getloadavg' ) ) {
			$load = @sys_getloadavg();
			if ( is_array( $load ) && isset( $load[0] ) ) {
				return (float) $load[0];
			}
		}

		if ( @is_readable( '/proc/loadavg' ) ) {
			$raw = @file_get_contents( '/proc/loadavg' );
			if ( $raw && preg_match( '/^([\d.]+)/', $raw, $m ) ) {
				return (float) $m[1];
			}
		}

		return null; // Unknown host capability — callers treat null as "can't check, assume safe."
	}

	public static function get_memory_percent(): ?float {
		if ( ! @is_readable( '/proc/meminfo' ) ) {
			return null;
		}

		$raw = @file_get_contents( '/proc/meminfo' );
		if ( ! $raw ) {
			return null;
		}

		if ( ! preg_match( '/MemTotal:\s+(\d+)/', $raw, $total ) ) {
			return null;
		}
		if ( ! preg_match( '/MemAvailable:\s+(\d+)/', $raw, $avail ) ) {
			return null; // Older kernels lack MemAvailable — no reliable fallback, don't guess.
		}

		$total_kb = (float) $total[1];
		if ( $total_kb <= 0 ) {
			return null;
		}

		$used_kb = $total_kb - (float) $avail[1];
		return round( ( $used_kb / $total_kb ) * 100, 1 );
	}

	public static function has_php_headroom( int $min_headroom_mb ): bool {
		$limit_bytes = (int) ini_get( 'memory_limit' ) * 1024 * 1024;
		if ( $limit_bytes <= 0 ) {
			return true; // Unlimited (-1) or unset — nothing to guard against.
		}

		$headroom_bytes = $limit_bytes - memory_get_usage( true );
		return $headroom_bytes >= ( $min_headroom_mb * 1024 * 1024 );
	}

	/* ── Private helpers ─────────────────────────────────────────────────── */

	private static function log( string $message ): void {
		if ( class_exists( 'MMI_Logger' ) ) {
			MMI_Logger::debug( $message, array(), 'throttler', 'MMI_Resource_Guard' );
		}
	}
}
