<?php
/**
 * Fetches each product's own vendor page and keeps its descriptive copy
 * (meta/og description, JSON-LD description, headings, opening paragraphs)
 * in Descriptions.vendor_copy — the source the user named for title
 * descriptions. Product Data Quality Phase 6, first slice.
 *
 * Scope (user decision 2026-09-24): vendor/manufacturer sites only — the
 * stored `vendor_product_url` whose `vendor_product_url_verified` is TRUE,
 * or, where that is missing or dead, a page UrlFinder matched in the same
 * vendor's own sitemap (approved, or scored UrlFinder::AUTO_SCORE+). Never
 * search, never follow links, never competitors.
 *
 * Politeness: one page per Action Scheduler tick (MMI_Batch_Watchdog_Trait,
 * rate-limiting.md rule 7), MMI_API_Throttler per host (rule 6: separate key
 * per host, conservative gap), robots.txt honoured, an identifying UA, 429 →
 * penalize(). Skips (without failing) while the server is over the load
 * threshold, so the item is simply retried on the next run.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VendorFetcher {

	use \MMI_Batch_Watchdog_Trait;

	const BATCH_KEY     = 'mmpt_vendor_fetch';
	const TICK_HOOK     = 'mmpt_vendor_fetch_tick';
	const WATCHDOG_HOOK = 'mmpt_vendor_fetch_watchdog';
	const HOST_GAP_MS   = 4000;
	const TIMEOUT_S     = 15;
	const MAX_BYTES     = 1500000;
	const LOAD_LIMIT    = 2.5;
	const REFETCH_DAYS  = 30;

	/**
	 * Fetch outcomes that won't change on a retry soon. A refusal (403) or a
	 * bot challenge (202) used to be retried every night with the same
	 * result; those pages now wait REFETCH_DAYS, or the browser render tier.
	 */
	/**
	 * Results checked again sooner: makers often publish a new product's page after we list it (XLN), and a
	 * robots.txt refusal stood from before robots were ignored by default (one distributor's 43, 2026-10-16).
	 */
	const GONE_STATUSES      = [ 'http_404', 'http_410', 'robots_disallow' ];
	const GONE_REFETCH_DAYS  = 2;
	// Verified pages with no stored copy at all (read before the page store kept copies, 2026-10-06 and earlier:
	// 4,002 on 2026-10-16) are read on the next nights whatever their age, this many a night, so maps, coverage
	// and scraping can see them. Read only: nothing is written to the product.
	const NEVER_STORED_PER_NIGHT = 1000;

	const FINAL_STATUSES = [ 'ok', 'no_copy', 'robots_disallow', 'http_404', 'http_410', 'http_403', 'http_202', 'moved', 'moved_offsite', 'redirect_loop' ]; // moved*: the page's new address is decided on the product (Scraper::follow_move())

	public static function register_hooks(): void {
		add_action( self::TICK_HOOK, [ __CLASS__, 'batch_tick_dispatch' ], 10, 3 );
		add_action( self::WATCHDOG_HOOK, [ __CLASS__, 'batch_watchdog_dispatch' ], 10, 1 );
	}

	/** How long a stored result of $status stands before the page is fetched again (never longer than $max_days). */
	public static function keep_days( string $status, int $max_days ): int {
		return in_array( $status, self::GONE_STATUSES, true ) ? min( $max_days, self::GONE_REFETCH_DAYS ) : $max_days;
	}

	/** Queue every product that needs its page (never fetched, retryable failure, older than REFETCH_DAYS, or missing for GONE_REFETCH_DAYS). */
	public static function start(): int {
		global $wpdb;
		$state = Module::state( 'mmi_admin_product_titles_fetch_state' );
		if ( ( $state['status'] ?? '' ) === 'running' && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::TICK_HOOK, null, Module::GROUP ) ) {
			return 0; // A run is still working through its queue; the nightly cron must not start a second.
		}
		$d      = Descriptions::table();
		$c      = UrlFinder::table();
		$final  = "'" . implode( "','", self::FINAL_STATUSES ) . "'";
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::REFETCH_DAYS * DAY_IN_SECONDS );
		$gone   = "'" . implode( "','", self::GONE_STATUSES ) . "'";
		$gcut   = gmdate( 'Y-m-d H:i:s', time() - self::GONE_REFETCH_DAYS * DAY_IN_SECONDS );
		$found  = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT c.product_id FROM {$c} c
					 JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish'
					 LEFT JOIN {$d} d ON d.product_id = c.product_id
					 WHERE ( c.status = 'approved' OR ( c.status = 'candidate' AND c.score >= %d ) )
					   AND ( d.product_id IS NULL OR d.vendor_url <> c.url OR d.fetch_status NOT IN ({$final}) OR d.fetched_at < %s OR ( d.fetch_status IN ({$gone}) AND d.fetched_at < %s ) )",
					UrlFinder::AUTO_SCORE,
					$cutoff,
					$gcut
				)
			)
		);
		$ids    = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url' AND u.meta_value LIKE 'http%%'
					 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
					 LEFT JOIN {$d} d ON d.product_id = p.ID
					 WHERE p.post_type = 'product' AND p.post_status = 'publish'
					   AND ( d.product_id IS NULL OR d.fetch_status NOT IN ({$final}) OR d.fetched_at < %s OR ( d.fetch_status IN ({$gone}) AND d.fetched_at < %s ) )
					 ORDER BY p.ID",
					$cutoff,
					$gcut
				)
			)
		);
		$pg     = PageStore::table();
		$stored = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url' AND u.meta_value LIKE 'http%%'
					 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
					 LEFT JOIN {$pg} pg ON pg.url_hash = MD5( u.meta_value )
					 LEFT JOIN {$d} d ON d.product_id = p.ID
					 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND pg.url_hash IS NULL
					   AND COALESCE( d.fetch_status, '' ) NOT IN ('manual','no_page')" . Exclusions::sql_not_excluded( 'p.ID' ) . '
					 ORDER BY p.ID LIMIT %d',
					self::NEVER_STORED_PER_NIGHT
				)
			)
		);
		$ids = Exclusions::filter( array_values( array_unique( array_merge( $ids, $found, $stored ) ) ) );
		sort( $ids );
		if ( $ids ) {
			// The scrape queue's runner reads them back to back (2026-10-08); one page per Action
			// Scheduler tick took ~40 s a page, five hours a night.
			Scraper::enqueue( $ids, 'read', false, 'nightly' );
			Scraper::kick();
			// Scraper::finish_nightly() marks it complete once the runner has read every nightly row.
			Module::set_state( 'mmi_admin_product_titles_fetch_state', [ 'status' => 'queued', 'started_at' => current_time( 'mysql' ), 'started_gmt' => current_time( 'mysql', true ), 'queued' => count( $ids ), 'never_stored' => count( $stored ), 'completed_at' => '', 'totals' => [] ] );
		}
		Module::info( 'Vendor page fetch started', [ 'queued' => count( $ids ), 'never_stored' => count( $stored ) ] );
		return count( $ids );
	}

	/* ── MMI_Batch_Watchdog_Trait contract ──────────────────────────────── */

	protected static function batch_hook_group( string $batch_key ): string {
		return Module::GROUP;
	}

	protected static function batch_empty_totals( string $batch_key ): array {
		return [ 'ok' => 0, 'no_copy' => 0, 'skipped' => 0, 'failed' => 0 ];
	}

	protected static function batch_on_complete( string $batch_key, array $totals ): void {
		Module::set_state( 'mmi_admin_product_titles_fetch_state', [ 'status' => 'complete', 'completed_at' => current_time( 'mysql' ), 'totals' => $totals ] );
		Module::info( 'Vendor page fetch complete', $totals );
	}

	protected static function batch_process_item( string $batch_key, $item, int $cursor ): array {
		$id  = (int) $item;
		if ( in_array( Descriptions::get_many( [ $id ] )[ $id ]['fetch_status'] ?? '', [ 'manual', UrlFinder::NO_PAGE ], true ) ) {
			return [ 'skipped' => 1 ]; // copy a person entered (Descriptions::set_manual) is never overwritten; a product a person said has no page isn't read
		}
		$url = esc_url_raw( UrlFinder::usable_url( $id ) ?: (string) get_post_meta( $id, 'vendor_product_url', true ) );
		if ( $url === '' ) {
			return [ 'skipped' => 1 ];
		}
		$load = function_exists( 'sys_getloadavg' ) ? (float) ( sys_getloadavg()[0] ?? 0 ) : 0.0;
		if ( $load > self::LOAD_LIMIT ) {
			Descriptions::upsert( $id, [ 'vendor_url' => $url, 'fetch_status' => 'deferred_load' ] );
			return [ 'skipped' => 1 ];
		}
		$result = self::fetch( $url );
		Descriptions::upsert(
			$id,
			[
				'vendor_url'   => $url,
				'vendor_copy'  => $result['copy'],
				'fetch_status' => $result['status'],
				'fetched_at'   => current_time( 'mysql', true ),
			]
		);
		if ( $result['status'] === 'ok' ) {
			Descriptions::refresh( [ $id ] ); // New copy → the row moves to the picker now, not at the next daily refresh.
			return [ 'ok' => 1 ];
		}
		return $result['status'] === 'no_copy' ? [ 'no_copy' => 1 ] : [ 'failed' => 1 ];
	}

	/* ── Fetch + extract ────────────────────────────────────────────────── */

	/**
	 * A vendor page's copy. Pages come through PageStore (Acquisition Phase 1,
	 * 2026-10-06): one fetch per URL however many products share it, the HTML
	 * kept for re-extraction, robots.txt and pacing per host policy.
	 *
	 * @return array{status:string,copy:string}
	 */
	public static function fetch( string $url ): array {
		$page = PageStore::get( $url );
		if ( $page['status'] !== 'ok' ) {
			return [ 'status' => $page['status'], 'copy' => '' ];
		}
		$copy = self::extract_copy( $page['html'] );
		return [ 'status' => $copy === '' ? 'no_copy' : 'ok', 'copy' => $copy ];
	}

	/**
	 * The page's own descriptive copy, most descriptive first: meta/og/twitter
	 * description, JSON-LD Product description, h1/h2, then opening paragraphs.
	 */
	/** Separates the page's meta/summary copy from its body copy inside vendor_copy. */
	const BODY_MARK = ' ¶¶ ';

	public static function extract_copy( string $html ): string {
		$blocks = [];
		$seen   = [];
		$part   = 'meta';
		$add    = static function ( string $text ) use ( &$blocks, &$seen, &$part ) {
			$t = Descriptions::plain( $text );
			if ( mb_strlen( $t ) >= 15 && ! isset( $seen[ mb_strtolower( $t ) ] ) ) {
				$seen[ mb_strtolower( $t ) ] = true;
				$blocks[ $part ][]           = $t;
			}
		};
		if ( preg_match_all( '/<meta\s[^>]*(?:name|property)\s*=\s*["\'](?:description|og:description|twitter:description)["\'][^>]*>/i', $html, $mm ) ) {
			foreach ( $mm[0] as $tag ) {
				if ( preg_match( '/content\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $c ) ) {
					$add( $c[2] !== '' ? $c[2] : ( $c[3] ?? '' ) );
				}
			}
		}
		if ( preg_match_all( '/<script[^>]+application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $mm ) ) {
			foreach ( $mm[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				foreach ( ( isset( $data['@graph'] ) ? $data['@graph'] : [ $data ] ) as $node ) {
					if ( is_array( $node ) && in_array( $node['@type'] ?? '', [ 'Product', 'SoftwareApplication' ], true ) && is_string( $node['description'] ?? null ) ) {
						$add( mb_substr( $node['description'], 0, 600 ) );
					}
				}
			}
		}
		$part = 'body';
		$body = preg_replace( '/<(script|style|nav|header|footer|noscript|form)\b.*?<\/\1>/is', ' ', $html );
		if ( preg_match_all( '/<h[12][^>]*>(.*?)<\/h[12]>/is', $body, $mm ) ) {
			foreach ( array_slice( $mm[1], 0, 4 ) as $h ) {
				$add( $h );
			}
		}
		if ( preg_match_all( '/<p[^>]*>(.*?)<\/p>/is', $body, $mm ) ) {
			$n = 0;
			foreach ( $mm[1] as $p ) {
				if ( mb_strlen( Descriptions::plain( $p ) ) >= 40 && $n++ < 6 ) {
					$add( $p );
				}
			}
		}
		// Blocks are joined with " ¶ " so a verified phrase can't straddle two of them; BODY_MARK
		// splits meta/summary copy (fine for title descriptions) from body copy (page content).
		return mb_substr( implode( ' ¶ ', $blocks['meta'] ?? [] ) . self::BODY_MARK . implode( ' ¶ ', $blocks['body'] ?? [] ), 0, Descriptions::COPY_CHARS * 2 );
	}

	/** Minimal robots.txt check for our UA and "*" (cached a day per host). */
	public static function robots_allows( string $url, string $host ): bool {
		$cache_key = 'mmpt_robots_' . md5( $host );
		$rules     = get_transient( $cache_key );
		if ( ! is_array( $rules ) ) {
			\MMI_API_Throttler::throttle( self::throttle_key( $host ), [ 'min_gap_ms' => self::HOST_GAP_MS ] );
			$res   = wp_remote_get( 'https://' . $host . '/robots.txt', [ 'timeout' => 8, 'user-agent' => FetchPolicy::bot_ua(), 'limit_response_size' => 200000 ] );
			$rules = [];
			if ( ! is_wp_error( $res ) && (int) wp_remote_retrieve_response_code( $res ) === 200 ) {
				$applies = false;
				foreach ( preg_split( '/\r?\n/', (string) wp_remote_retrieve_body( $res ) ) as $line ) {
					$line = trim( preg_replace( '/#.*$/', '', $line ) );
					if ( preg_match( '/^user-agent:\s*(.+)$/i', $line, $m ) ) {
						$ua      = strtolower( trim( $m[1] ) );
						$applies = $ua === '*' || strpos( $ua, FetchPolicy::bot_token() ) !== false;
					} elseif ( $applies && preg_match( '/^disallow:\s*(\S+)/i', $line, $m ) ) {
						$rules[] = $m[1];
					}
				}
			}
			set_transient( $cache_key, $rules, DAY_IN_SECONDS );
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH ) ?: '/';
		foreach ( $rules as $prefix ) {
			if ( $prefix !== '' && strpos( $path, rtrim( $prefix, '*' ) ) === 0 ) {
				return false;
			}
		}
		return true;
	}

	public static function throttle_key( string $host ): string {
		return 'vendor_page_' . substr( preg_replace( '/[^a-z0-9]+/', '_', $host ), 0, 60 );
	}
}
