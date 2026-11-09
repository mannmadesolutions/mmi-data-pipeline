<?php
/**
 * Vendor-page scrape queue and its runner (owner 2026-10-08: "The scraping
 * process is taking too long to wait every night"; bulk Read / Scrape on the
 * Vendor pages tab).
 *
 * The nightly read used to go through MMI_Batch_Watchdog_Trait: one page per
 * Action Scheduler tick, and ticks run about once a minute, so 469 pages took
 * five hours (04:08–09:22 on 2026-10-08), mostly waiting. Now every read —
 * nightly (VendorFetcher::start()), a bulk action or "queue all shown" — goes
 * into `wp_mmpt_scrape_queue`, and one WP-CLI runner (`wp mmpt scrape-run`,
 * started detached by kick()) works through it without stopping: products
 * are taken host by host in turn, so PageStore's per-host gap and robots.txt
 * still apply but rarely make it wait; it pauses while the server is over its
 * load/RAM limits. A MySQL named lock keeps it to one runner; if it dies the
 * lock goes with its connection and the next kick() starts another.
 *
 * Modes: `read` fetches the page and keeps its copy (what the nightly read
 * always did); `scrape` also builds the product's description and picture
 * proposals and reads what the page offers, at once, instead of waiting for
 * the daily proposal run.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Scraper {

	const DB_OPTION  = 'mmpt_scrape_db_version';
	const DB_VERSION = '1';
	const LOCK       = 'mmpt_scrape_runner';
	const STATE      = 'mmi_admin_product_titles_scrape_state';
	const MODES      = [ 'read', 'scrape' ];
	const CHUNK      = 200;                // pending rows taken per round
	const MAX_RUN_S  = 6 * HOUR_IN_SECONDS; // a runner stops after this; the next kick() carries on
	const BUSY_SLEEP = 15;
	const SHARED_FRESH_S = 10 * MINUTE_IN_SECONDS; // a page several products share is fetched once in this window, not once per product

	/**
	 * The parts process_one() may write (description, pictures, requirements, features, manual, videos, audio), or
	 * null for all: the Chrome extension commits only the parts a person ticked (ProductPreview::write()).
	 * @var string[]|null
	 */
	public static $only = null;

	private static function may( string $part ): bool {
		return self::$only === null || in_array( $part, self::$only, true );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_scrape_queue';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  product_id bigint(20) unsigned NOT NULL,
  mode varchar(10) NOT NULL DEFAULT 'read',
  force_fetch tinyint(1) NOT NULL DEFAULT 0,
  source varchar(20) NOT NULL DEFAULT 'manual',
  queued_at datetime DEFAULT NULL,
  started_at datetime DEFAULT NULL,
  done_at datetime DEFAULT NULL,
  result varchar(40) NOT NULL DEFAULT '',
  PRIMARY KEY  (product_id),
  KEY pending (done_at, queued_at)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Add products to the queue. A product already waiting keeps its place
	 * (and becomes `scrape` if either asks for it); a finished one is queued
	 * again. @return int products now waiting from this call
	 */
	public static function enqueue( array $ids, string $mode = 'read', bool $force = false, string $source = 'manual' ): int {
		self::maybe_install();
		global $wpdb;
		$mode = in_array( $mode, self::MODES, true ) ? $mode : 'read';
		$ids  = Exclusions::filter( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ) );
		$t    = self::table();
		$now  = current_time( 'mysql', true );
		$n    = 0;
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $id ) {
				$values[] = $wpdb->prepare( '(%d,%s,%d,%s,%s,NULL,NULL,%s)', $id, $mode, (int) $force, $source, $now, '' );
			}
			// Waiting rows keep their place; finished ones start over.
			$wpdb->query(
				"INSERT INTO {$t} (product_id, mode, force_fetch, source, queued_at, started_at, done_at, result) VALUES " . implode( ',', $values ) . "
				 ON DUPLICATE KEY UPDATE
				   mode = IF( done_at IS NULL AND mode = 'scrape', 'scrape', VALUES(mode) ),
				   force_fetch = GREATEST( IF( done_at IS NULL, force_fetch, 0 ), VALUES(force_fetch) ),
				   source = IF( done_at IS NULL AND source = 'manual', 'manual', VALUES(source) ),
				   queued_at = IF( done_at IS NULL, queued_at, VALUES(queued_at) ),
				   started_at = IF( done_at IS NULL, started_at, NULL ),
				   result = IF( done_at IS NULL, result, '' ),
				   done_at = NULL" // phpcs:ignore WordPress.DB.PreparedSQL
			);
			$n += count( $chunk );
		}
		if ( $n ) {
			Module::info( 'Vendor pages queued', [ 'count' => $n, 'mode' => $mode, 'source' => $source ] );
		}
		return $n;
	}

	/** Drop rows that haven't started. @return int */
	public static function clear_pending(): int {
		self::maybe_install();
		global $wpdb;
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . ' WHERE done_at IS NULL AND started_at IS NULL' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function runner_alive(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::LOCK ) ) !== null;
	}

	/**
	 * Start the runner, detached, unless one is running or nothing waits.
	 * From a web request too (proc_open is allowed here); `wp mmpt pick`
	 * every 30 minutes calls this as well, so a failed start catches up.
	 */
	public static function kick(): bool {
		self::maybe_install();
		global $wpdb;
		if ( self::runner_alive() || ! (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE done_at IS NULL' ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return false;
		}
		if ( ! self::spawn( 'mmpt scrape-run', 'scrape-runner.log' ) ) {
			return false;
		}
		Module::set_state( self::STATE, [ 'kicked_at' => current_time( 'mysql', true ) ] );
		return true;
	}

	/**
	 * Start `wp <$command>` detached in its own session, output appended to
	 * $log_name in the private log folder. Also starts the picture runner.
	 */
	public static function spawn( string $command, string $log_name ): bool {
		// No is_executable() check: under PHP-FPM, open_basedir hides /usr/local/bin and /bin from
		// PHP's file functions (it returned false for both), but proc_open still runs them.
		if ( ! function_exists( 'proc_open' ) ) {
			Module::warn( 'Background runner could not start: proc_open unavailable', [ 'command' => $command ] );
			return false;
		}
		$wp   = 'wp'; // found on PATH (/usr/local/bin)
		$dir  = mmi_shared_lib_private_subdir( 'mmpt-logs' );
		$log  = $dir !== '' ? $dir . $log_name : '/dev/null';
		$args = implode( ' ', array_map( 'escapeshellarg', preg_split( '/\s+/', trim( $command ) ) ) );
		$cmd  = 'setsid nohup ' . escapeshellarg( $wp ) . ' ' . $args . ' --path=' . escapeshellarg( untrailingslashit( ABSPATH ) ) . ' >> ' . escapeshellarg( $log ) . ' 2>&1 < /dev/null &';
		$home = getenv( 'HOME' ) ?: '/home/runcloud';
		// Pipes, not ['file','/dev/null']: PHP opens descriptor files itself and open_basedir blocks /dev.
		// The shell redirects the runner's own input and output (the command line above).
		$proc = proc_open( [ '/bin/sh', '-c', $cmd ], [ 0 => [ 'pipe', 'r' ], 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ], $pipes, ABSPATH, [ 'PATH' => '/usr/local/bin:/usr/bin:/bin', 'HOME' => $home ] );
		if ( ! is_resource( $proc ) ) {
			Module::warn( 'Background runner could not start: proc_open failed', [ 'command' => $command ] );
			return false;
		}
		foreach ( $pipes as $pipe ) {
			fclose( $pipe ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		proc_close( $proc ); // the shell returns at once; the runner lives on in its own session
		return true;
	}

	/**
	 * The runner (WP-CLI). Works until the queue is empty or MAX_RUN_S.
	 * @return array<string,int|string>
	 */
	public static function run(): array {
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'error' => 'Runs from WP-CLI only (wp mmpt scrape-run).' ];
		}
		self::maybe_install();
		global $wpdb;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return [ 'skipped' => 'another runner is working' ];
		}
		$t     = self::table();
		$start = time();
		$out   = [ 'done' => 0 ];
		$hosts = []; // sites read in this run: their Vendor maps numbers are brought up to date after it
		Module::set_state( self::STATE, [ 'started_at' => current_time( 'mysql', true ), 'finished_at' => '', 'pid' => getmypid() ] );
		while ( time() - $start < self::MAX_RUN_S ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT q.product_id, q.mode, q.force_fetch, d.vendor_url, u.meta_value stored
					 FROM {$t} q
					 LEFT JOIN " . Descriptions::table() . " d ON d.product_id = q.product_id
					 LEFT JOIN {$wpdb->postmeta} u ON u.post_id = q.product_id AND u.meta_key = 'vendor_product_url'
					 WHERE q.done_at IS NULL
					 ORDER BY q.source = 'manual' DESC, q.queued_at, q.product_id
					 LIMIT %d",
					self::CHUNK
				),
				ARRAY_A
			);
			if ( ! $rows ) {
				break;
			}
			foreach ( self::interleave( $rows ) as $r ) {
				while ( PageStore::server_busy() ) {
					Module::set_state( self::STATE, [ 'beat_at' => current_time( 'mysql', true ), 'note' => 'waiting: server busy' ] );
					sleep( self::BUSY_SLEEP );
				}
				$id = (int) $r['product_id'];
				// Stopped meanwhile (Stop on the tab), or taken by an earlier pass.
				if ( ! $wpdb->update( $t, [ 'started_at' => current_time( 'mysql', true ) ], [ 'product_id' => $id, 'done_at' => null ] ) ) {
					continue;
				}
				try {
					$res = self::process_one( $id, (string) $r['mode'], (bool) $r['force_fetch'] );
				} catch ( \Throwable $e ) {
					$res = 'error';
					Module::warn( 'Vendor page scrape failed', [ 'product_id' => $id, 'error' => $e->getMessage() ] );
				}
				$wpdb->update( $t, [ 'done_at' => current_time( 'mysql', true ), 'result' => mb_substr( $res, 0, 40 ) ], [ 'product_id' => $id ] );
				try {
					FillRun::scraped( $id ); // a Fill waiting on this product keeps what it wrote
				} catch ( \Throwable $e ) {
					Module::warn( 'Fill record failed', [ 'product_id' => $id, 'error' => $e->getMessage() ] );
				}
				$hosts[ (string) wp_parse_url( (string) ( $r['vendor_url'] ?: $r['stored'] ), PHP_URL_HOST ) ] = true;
				$out['done']++;
				$out[ $res ] = ( $out[ $res ] ?? 0 ) + 1;
				Module::set_state( self::STATE, [ 'beat_at' => current_time( 'mysql', true ), 'note' => '', 'last_id' => $id ] );
				if ( time() - $start >= self::MAX_RUN_S ) {
					break 2;
				}
			}
		}
		Module::set_state( self::STATE, [ 'finished_at' => current_time( 'mysql', true ), 'last_run' => $out ] );
		Module::info( 'Vendor page runner finished', $out );
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		VendorMaps::queue_refresh( array_keys( $hosts ) ); // the maps list's chips and picture bars follow what was just read
		self::finish_nightly();
		if ( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE done_at IS NULL" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			self::kick(); // ran out of time with work left: hand over to a fresh runner
		}
		return $out;
	}

	/**
	 * The nightly read (VendorFetcher::start()) is complete once no nightly row waits: its state gets the
	 * finish time and what the reads came to. Since the queue runner replaced Action Scheduler (2026-10-08)
	 * nothing did this, so the Applying tab said "queued" for ever.
	 */
	private static function finish_nightly(): void {
		global $wpdb;
		$key   = 'mmi_admin_product_titles_fetch_state';
		$state = Module::state( $key );
		$t     = self::table();
		if ( ( $state['status'] ?? '' ) !== 'queued' || (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE done_at IS NULL AND source = 'nightly'" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return;
		}
		$totals = [];
		if ( ! empty( $state['started_gmt'] ) ) {
			foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT result, COUNT(*) n FROM {$t} WHERE source = 'nightly' AND done_at >= %s GROUP BY result", $state['started_gmt'] ), ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
				$totals[ $r['result'] !== '' ? $r['result'] : 'unknown' ] = (int) $r['n'];
			}
		}
		Module::set_state( $key, [ 'status' => 'complete', 'completed_at' => current_time( 'mysql' ), 'totals' => $totals ] );
		Module::info( 'Vendor page fetch complete', $totals );
	}

	/**
	 * Read (and for `scrape`, propose for) one product: what the nightly read
	 * does (VendorFetcher), from the page store unless $force. @return string fetch status ('manual', 'no_url', or PageStore's)
	 */
	/**
	 * The vendor's edition comparison for an edition's Product Variants family, read once a day at most
	 * (Compare::capture() renders the family page; WP-CLI only, the scrape runner).
	 */
	/** A page other products share, fetched for one of them minutes ago: the others read that copy (11 Fender products, one page, 11 fetches in 42 s). */
	private static function just_read( string $url ): bool {
		if ( ! isset( SharedPages::shared_urls()[ $url ] ) ) {
			return false;
		}
		$row = PageStore::row( $url );
		return $row && $row['fetched_at'] && strtotime( $row['fetched_at'] . ' UTC' ) > time() - self::SHARED_FRESH_S;
	}

	/** An edition's page was read: a store with product families reads the family's comparison (this site: Product Variants). */
	private static function read_comparison( int $id ): void {
		do_action( 'mmi_pipeline_edition_page_read', $id );
	}

	public static function process_one( int $id, string $mode, bool $force ): string {
		if ( get_post_status( $id ) !== 'publish' || Exclusions::is_excluded( $id ) ) {
			return 'skipped';
		}
		$fs = Descriptions::get_many( [ $id ] )[ $id ]['fetch_status'] ?? '';
		if ( $fs === 'manual' ) {
			return 'manual'; // copy a person entered is never read over
		}
		if ( $fs === UrlFinder::NO_PAGE ) {
			return 'no_url'; // a person said it has no vendor page
		}
		$verified = UrlFinder::is_verified( get_post_meta( $id, 'vendor_product_url_verified', true ) ) ? (string) get_post_meta( $id, 'vendor_product_url', true ) : '';
		$url      = esc_url_raw( $verified ?: UrlFinder::usable_url( $id ) ); // the product's own page; a proposal waiting for a decision is never read as it
		if ( $url === '' ) {
			return 'no_url';
		}
		$afresh = $force && ! self::just_read( $url );
		if ( VendorMaps::render_for( $url ) && defined( 'WP_CLI' ) && WP_CLI ) {
			PageStore::render( $url, $afresh ? 0 : VendorFetcher::REFETCH_DAYS ); // a confirmed vendor map says this site draws its page with JavaScript
		} elseif ( $afresh ) {
			PageStore::get( $url, 0 ); // fetched afresh; robots.txt and per-host pacing as always
		}
		$res = VendorFetcher::fetch( $url );
		if ( in_array( $res['status'], [ 'moved', 'moved_offsite' ], true ) ) {
			[ $url, $res ] = self::follow_move( $id, $url, $res );
		}
		// A page several products share: what this product reads from it (SharedPages).
		$rel  = SharedPages::relation( $id, $url );
		$card = null;
		if ( $rel['kind'] === 'several' && in_array( $res['status'], [ 'ok', 'no_copy' ], true ) ) {
			global $wpdb;
			$file = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT html_file FROM ' . PageStore::table() . ' WHERE url_hash = %s', md5( $url ) ) );
			$card = $file !== '' ? SharedPages::card( PageStore::read( $file ), $id, $url ) : null;
			$res['copy'] = $card ? VendorFetcher::extract_copy( $card['html'] ) : ''; // never another product's words
			if ( $card && $card['link'] !== '' && ! UrlFinder::same_url( $card['link'], $url ) ) {
				// Its own page, for a person to approve: never over a decision about that page, or a stronger open proposal.
				$was = $wpdb->get_row( $wpdb->prepare( 'SELECT url, status, score FROM ' . UrlFinder::table() . ' WHERE product_id = %d', $id ), ARRAY_A );
				if ( ! $was || ( ! UrlFinder::same_url( (string) $was['url'], $card['link'] ) && ! ( $was['status'] === 'candidate' && (int) $was['score'] >= 90 ) ) ) {
					UrlFinder::offer( $id, $card['link'], 90, 'candidate', 'card_link', 'Its card on ' . $url . ' links to it' );
				}
			}
		}
		Descriptions::upsert( $id, [ 'vendor_url' => $url, 'vendor_copy' => $res['copy'], 'fetch_status' => $res['status'], 'fetched_at' => current_time( 'mysql', true ) ] );
		if ( $res['status'] === 'ok' ) {
			Descriptions::refresh( [ $id ] );
		}
		if ( $mode === 'scrape' && in_array( $res['status'], [ 'ok', 'no_copy' ], true ) ) {
			global $wpdb;
			$pg = $wpdb->get_row( $wpdb->prepare( 'SELECT status, html_file, content_hash FROM ' . PageStore::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
			if ( $pg ) {
				// First: what the page offers and its cleaned description (the description proposal reads it).
				Coverage::pages( [ [ 'url' => $url, 'status' => (string) $pg['status'], 'file' => (string) $pg['html_file'], 'hash' => (string) $pg['content_hash'], 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ] ], 60.0 );
				// Its system requirements into empty requirement fields (never over anything stored).
				$html = PageStore::read( (string) $pg['html_file'] );
				$req  = class_exists( PageRequirements::class ) ? PageRequirements::extract( VendorMaps::requirements_html( $url, $html, true ) ) : null; // a vendor map's requirements part, or the page its link opens
				if ( $req && self::may( 'requirements' ) ) {
					PageRequirements::fill_product( $id, $req );
				}
				// Empty features, manuals, videos and audio from the site's confirmed vendor map (or the generic reader); from
				// its card on a shared listing; a part the map keeps on the product's sub-page or one site page is read there.
				$own = $rel['kind'] === 'several' ? ( $card['html'] ?? '' ) : $html;
				if ( $own !== '' ) {
					VendorMaps::fill_product( $id, $url, $own, true, self::$only );
				}
				// An edition: its family's comparison read once, so its own edition's column reaches its description.
				if ( $rel['kind'] === 'editions' ) {
					self::read_comparison( $id );
				}
			}
			// What follows a read (description and picker rows, writes due anyway, first pictures) is the other modules' work.
			PageEvents::page_read( $id, [ 'description' => self::may( 'description' ), 'pictures' => self::may( 'pictures' ) ] );
		}
		return (string) $res['status'];
	}

	/**
	 * The product's page answered with a redirect to another address (PageStore, 2026-10-11).
	 * Same site and the new page names the product, or is rated UrlReview::FOLLOW or more (owner 2026-10-27:
	 * a product rated 95 on its moved page and still waited for a click; 2026-10-28: 75,
	 * expansion and add-on pages whose titles only lack tagline words): the URL
	 * follows it (approved, reason `moved`, the old address in the note) and the new page is read.
	 * Otherwise a person decides: the new address is proposed (`moved`, or `moved_offsite` for another
	 * site, never fetched) unless another proposal is waiting or this address was decided before.
	 *
	 * @return array{0:string,1:array} the URL read and its result
	 */
	private static function follow_move( int $id, string $url, array $res ): array {
		$page = PageStore::row( $url );
		$to   = (string) ( $page['final_url'] ?? '' );
		if ( $to === '' ) {
			return [ $url, $res ];
		}
		$offsite = $res['status'] === 'moved_offsite';
		$new     = $offsite ? null : PageStore::row( $to );
		$title   = html_entity_decode( (string) get_post_field( 'post_title', $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$brand   = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
		$brand   = is_array( $brand ) ? (string) ( $brand[0] ?? '' ) : '';
		$why = 'the page names the product';
		if ( $offsite || ! $new || ! UrlFinder::page_names_product( (string) $new['title'], $title, $brand ) ) {
			$open    = UrlFinder::row( $id );
			$waiting = $open && $open['status'] === 'candidate';
			if ( $open && ( $waiting xor UrlFinder::same_url( (string) $open['url'], $to ) ) ) {
				return [ $url, $res ]; // another proposal is waiting, or this address was decided before
			}
			if ( ! $waiting ) {
				UrlFinder::offer( $id, $to, $offsite ? 20 : 70, 'candidate', $offsite ? 'moved_offsite' : 'moved', ( $offsite ? 'the old address redirects to another site; check it is the vendor before approving. Was ' : 'the old address redirects here; was ' ) . $url );
			}
			$rated = $offsite || ! $new ? 0 : (int) ( UrlReview::evidence( [ $id ] )[ $id ]['confidence']['n'] ?? 0 );
			if ( $rated < UrlReview::FOLLOW ) {
				return [ $url, $res ];
			}
			$why = sprintf( 'the page is rated %d', $rated );
		}
		UrlFinder::offer( $id, $to, 100, 'approved', 'moved', sprintf( 'the old address redirects here and %s; was %s', $why, $url ) );
		UrlFinder::adopt( $id, $to );
		Module::info( 'Vendor URL followed a redirect', [ 'product_id' => $id, 'from' => $url, 'to' => $to, 'why' => $why ] );
		return [ $to, VendorFetcher::fetch( $to ) ];
	}

	/** For the tab's status bar and row badges. */
	public static function status(): array {
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$c   = $wpdb->get_row( "SELECT SUM(done_at IS NULL AND started_at IS NULL) waiting, SUM(done_at IS NULL AND started_at IS NOT NULL) working, SUM(done_at IS NOT NULL AND done_at > UTC_TIMESTAMP() - INTERVAL 1 DAY) done_today FROM {$t}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$res = [];
		foreach ( $wpdb->get_results( "SELECT result, COUNT(*) n FROM {$t} WHERE done_at > UTC_TIMESTAMP() - INTERVAL 1 DAY GROUP BY result", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$res[ $r['result'] ?: '?' ] = (int) $r['n'];
		}
		return [
			'running'    => self::runner_alive(),
			'waiting'    => (int) ( $c['waiting'] ?? 0 ),
			'working'    => (int) ( $c['working'] ?? 0 ),
			'done_today' => (int) ( $c['done_today'] ?? 0 ),
			'results'    => $res,
			'state'      => array_intersect_key( Module::state( self::STATE ), array_flip( [ 'started_at', 'finished_at', 'beat_at', 'note', 'kicked_at', 'last_run' ] ) ),
		];
	}

	/** Queue states for these products (row badges): id → waiting|working. @return array<int,string> */
	public static function states( array $ids ): array {
		self::maybe_install();
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return [];
		}
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT product_id, started_at, mode FROM ' . self::table() . ' WHERE done_at IS NULL AND product_id IN (' . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ (int) $r['product_id'] ] = ( $r['started_at'] ? 'working' : 'waiting' ) . ':' . $r['mode'];
		}
		return $out;
	}

	/** Rows ordered host by host in turn, so consecutive fetches rarely wait on one host's gap. */
	private static function interleave( array $rows ): array {
		$by = [];
		foreach ( $rows as $r ) {
			$url                                   = (string) ( $r['vendor_url'] ?: $r['stored'] );
			$by[ (string) wp_parse_url( $url, PHP_URL_HOST ) ][] = $r;
		}
		$out = [];
		while ( $by ) {
			foreach ( $by as $h => &$list ) {
				$out[] = array_shift( $list );
				if ( ! $list ) {
					unset( $by[ $h ] );
				}
			}
			unset( $list );
		}
		return $out;
	}
}
