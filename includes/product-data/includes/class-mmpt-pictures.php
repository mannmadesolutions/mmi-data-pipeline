<?php
/**
 * Product picture replacement (owner 2026-10-08; PRODUCT_IMAGE_REPLACEMENT_HANDOFF.md).
 *
 * Every product's pictures become 800×800 standard JPEGs (ImageConvert), and
 * the media they replace is released for deletion (MediaRelease). Per product,
 * plan() picks one of two modes:
 *
 *   vendor   — its verified vendor page offers 2+ structured pictures (JSON-LD,
 *              a gallery/profile container, or named after the product; owner
 *              D1: written without a pick). Since 2026-10-18 they are merged
 *              into the product's set, never swapped for it (PictureMatch):
 *              the product's own pictures stay (re-saved to the standard),
 *              a page copy with clearly more detail takes its picture's place,
 *              pictures the product lacks are added (no cap: owner 2026-10-30),
 *              and a second copy of one picture in the set is removed (the
 *              better copy stays). Each decision is in the ledger.
 *   in_place — the vendor page can't (none verified or stored, only a share
 *              banner, one picture, another version's page, low-res): the
 *              product keeps its own pictures, re-saved to the standard (owner
 *              D7). Every such product is flagged in the ledger, the run log
 *              and the summary table (owner: "an alert or notice of such
 *              should accompany the post-process logs and/or summary table").
 *
 * A product with no picture gets its first pictures from its vendor page
 * (one structured picture is enough: better than none). When the page has
 * none it can use, the fallbacks follow (owner 2026-10-28: "prioritize vendor
 * URLs and use available data … as a fallback"): the page's low-res pictures,
 * then its share image, then the distributors' pictures — Plugivery's (the
 * product's own Plugivery listing, or the same maker's product of the same
 * name) and XChange's (web-assets, then its feed). fallback_pictures(). Since 2026-10-08
 * (Descriptions & images folded into Vendor pages) this runner is the one
 * writer of product pictures; the picker only offers a choice where the
 * runner says a person's pick helps (one picture, a share banner, low-res).
 * "First pictures" are queued automatically (Enrichment::daily(), a Scrape)
 * while the owner's "pictures from the vendor page" approval is on: only
 * products with no picture, each at most once. They don't open a pass.
 *
 * Never: a product a person excluded in Image Trim; vendor pictures over a set
 * a person picked (in_place still shrinks those files); vendor pictures twice.
 *
 * `wp_mmpt_image_ledger` is the queue and the per-product record: old and new
 * sets, bytes before and after, mode and reason, so revert() puts any product
 * back. One WP-CLI runner (`wp mmpt images run`, started detached like the
 * scrape runner) works the queue: at most DAILY_CAP products a day (owner D5:
 * 300; each changed picture re-sends a Reverb listing's photos), pausing while
 * a person runs a sync, import or fetch (Activity) and slowing while anything
 * heavy runs.
 *
 * One pass, not a standing job (owner 2026-10-08: "isn't meant to run in
 * perpetuity"): nothing queues products automatically. A person queues them
 * (Vendor pages tab or CLI), which opens a pass; the watchdog may only
 * restart the runner to finish that pass (after the daily cap, a crash). When
 * the queue empties the pass is closed and automatic restarts stop until a
 * person queues products again. A product whose pictures were changed is
 * never processed again unless a person asks (`enqueue( …, again: true )`).
 * The cron still moves released files to quarantine once their wait is over:
 * that finishes the pass's cleanup and runs out by itself.
 *
 * Never a stalled queue (owner 2026-10-31, after a runner sat "paused" for
 * hours and the next lasted 30 minutes "not running"): the runner is short-
 * lived (MAX_RUN_S, and it stops when a new version is deployed, so it never
 * runs old code), checks a pause every PAUSE_S and beats each time. The
 * watchdog (WP-Cron every minute, and every screen that polls queue_status())
 * starts one wherever a pass waits, and stops one whose beat is older than
 * STALL_S so the next takes over. A person never waits on a restart.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pictures {

	const DB_OPTION   = 'mmpt_pictures_db_version';
	const DB_VERSION  = '2'; // 2: decisions (the merge, picture by picture)
	const LOCK        = 'mmpt_pictures_runner';
	const STATE       = 'mmi_admin_product_pictures_state';
	const DAILY_CAP   = 300;
	const MIN_AUTO    = 1;      // structured pictures a page needs: they merge, never removing the product's own (owner 2026-10-21, "all images"; D1's 2 was for replacing)
	const LIMITS_KEY  = 'mmi_admin_product_pictures_limits'; // a pass's own limits { cap, calls, until } (owner 2026-10-21)
	const MAX_RUN_S   = HOUR_IN_SECONDS; // then the watchdog starts a fresh one (fresh code, fresh caches)
	const PAUSE_S     = 30;     // re-check a person's sync this often (and beat)
	const STALL_S     = 20 * MINUTE_IN_SECONDS; // a runner with no beat this long is hung: the watchdog stops it (one product has never taken 14)
	const START_S     = 90;     // a runner started this recently is still starting: not started twice
	const SLOW_S      = 60;     // one product a minute while something heavy runs
	const STRUCTURED  = [ 'json_ld', 'profile', 'gallery', 'named' ];
	const SITE_WIDE_SHARE = 3; // a share image this many of a site's stored pages give is the site's, not the product's
	/** Queue source of a person's picture review (Product Data → gaps → Review pictures): planned with plan( review: true ). */
	const REVIEW      = 'review';
	/** The runner's watchdog (WP-Cron, which the server's crontab runs every minute) and how often it runs. */
	const HEARTBEAT   = 'mmpt_pictures_heartbeat';
	const HEARTBEAT_S = MINUTE_IN_SECONDS;
	const SCHEDULE    = 'mmpt_minute';
	const PACE_ROWS   = 30;     // recent products the runner's pace is measured over (queue_status())
	/** The order the runner takes queued products in: a person's (a fill, a review) first, then the lightest, oldest. */
	const QUEUE_ORDER = "source IN ('manual','review') DESC, priority DESC, queued_at, product_id";
	/**
	 * Pictures a person picked on the product's page in Chrome (the extension, owner 2026-11-01: "straight to a
	 * product"): { urls, page, at, by }. Until the runner has handled the product since `at`, plan() offers these as the
	 * page's pictures, so they go through the normal merge (added, never replacing; no duplicates; vetoes apply).
	 */
	const PICKED_META = '_mmpt_picked_pictures';
	const MAX_PICKED  = 40;

	/** Why a product's vendor page couldn't supply its pictures (in_place reason) or why it was left alone. */
	const REASONS = [
		'no_verified_page'   => 'No verified vendor URL',
		'page_not_read'      => 'The vendor page has not been read yet (the runner reads it first)',
		'page_not_ok'        => 'The vendor page could not be read',
		'page_other_version' => 'The vendor page is about another version',
		'page_no_card'       => 'The vendor page lists several products and none of its cards names this one',
		'page_no_pictures'   => 'The vendor page has no pictures',
		'page_share_only'    => 'The vendor page has only a share banner',
		'page_one_picture'   => 'The vendor page has one picture (a person can pick it)',
		'page_low_res'       => 'The vendor page pictures are too small',
		'person_picked'      => 'A person picked these pictures',
		'vendor_done'        => 'Vendor pictures were already written once',
		'vendor_failed'      => 'Vendor pictures could not be downloaded',
		'kept_by_person'     => 'Excluded in Image Trim by a person',
		'no_pictures'        => 'No pictures, and the vendor page has none usable',
		'already_standard'   => 'Every picture is already standard',
		'nothing_to_add'     => 'The vendor page has no picture the product lacks',
		'not_eligible'       => 'Not a published product this module handles',
		// First pictures from a fallback (the product had none and its vendor page had none it could use).
		'first_low_res'      => 'First pictures: the vendor page\'s small pictures',
		'first_share'        => 'First picture: the vendor page\'s share image',
		'first_plugivery'    => 'First pictures: Plugivery\'s pictures of this product',
		'first_xchange'      => 'First pictures: XChange\'s pictures of this product',
	];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_image_ledger';
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
  status varchar(16) NOT NULL DEFAULT 'queued',
  mode varchar(10) NOT NULL DEFAULT '',
  reason varchar(40) NOT NULL DEFAULT '',
  source varchar(10) NOT NULL DEFAULT 'manual',
  priority bigint(20) unsigned NOT NULL DEFAULT 0,
  source_url varchar(500) NOT NULL DEFAULT '',
  old_thumb bigint(20) unsigned NOT NULL DEFAULT 0,
  old_gallery text,
  new_ids text,
  map text,
  pics_before smallint(5) unsigned NOT NULL DEFAULT 0,
  pics_after smallint(5) unsigned NOT NULL DEFAULT 0,
  bytes_before bigint(20) unsigned NOT NULL DEFAULT 0,
  bytes_after bigint(20) unsigned NOT NULL DEFAULT 0,
  locked_by_us tinyint(1) NOT NULL DEFAULT 0,
  note varchar(255) NOT NULL DEFAULT '',
  decisions longtext,
  queued_at datetime DEFAULT NULL,
  run_at datetime DEFAULT NULL,
  reverted_at datetime DEFAULT NULL,
  PRIMARY KEY  (product_id),
  KEY queue (status, source, priority),
  KEY run (status, run_at)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function register_hooks(): void {
		self::maybe_install();
		MediaRelease::maybe_install();
		ImageConvert::register_hooks();
		// Owner D2 (2026-10-08): product pictures come from vendor pages only; imports
		// (Data Pipeline profiles, XChange vendor media) no longer set them on any product.
		add_filter( 'mmi_import_product_images', '__return_false' );
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		// The watchdog, every minute (after the daily limit, the 08:00 reboot, a hung runner): owner 2026-10-31,
		// "Waiting for the picture runner" has to be true. What `wp mmpt heartbeat` does for pictures only.
		add_filter(
			'cron_schedules',
			static function ( $s ) {
				$s[ self::SCHEDULE ] = $s[ self::SCHEDULE ] ?? [ 'interval' => self::HEARTBEAT_S, 'display' => 'Every minute (MMI product pictures watchdog)' ];
				return $s;
			}
		);
		add_action( self::HEARTBEAT, [ __CLASS__, 'heartbeat' ] );
		if ( wp_get_schedule( self::HEARTBEAT ) !== self::SCHEDULE ) { // none yet, or the old 30-minute one
			wp_clear_scheduled_hook( self::HEARTBEAT );
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::SCHEDULE, self::HEARTBEAT );
		}
	}

	/** WP-Cron, every minute: the watchdog (ensure()), and when it last looked. */
	public static function heartbeat(): void {
		Module::set_state( self::STATE, [ 'heartbeat_at' => current_time( 'mysql', true ) ] );
		self::ensure( true );
	}

	/**
	 * The watchdog: a runner wherever a pass waits, and none hung. A runner whose beat is older than STALL_S is
	 * stopped ($stop: WP-Cron only, which runs as the runner's own user and can see /proc); the next look starts
	 * a fresh one, which puts its product back in line.
	 * @return string alive | started | starting | stopped (a hung runner) | hung (one it couldn't stop) | idle
	 */
	public static function ensure( bool $stop = false ): string {
		if ( ! self::runner_alive() ) {
			return self::kick( true ) ? ( self::starting() ? 'starting' : 'started' ) : 'idle';
		}
		if ( ! self::hung() ) {
			return 'alive';
		}
		$pid = (int) ( Module::state( self::STATE )['pid'] ?? 0 );
		// Only our runner: its own command line, never a pid the system has since given to something else.
		$cmd = $stop && $pid > 1 && function_exists( 'posix_kill' ) && is_readable( "/proc/{$pid}/cmdline" ) ? str_replace( "\0", ' ', (string) file_get_contents( "/proc/{$pid}/cmdline" ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! str_contains( $cmd, 'mmpt images run' ) ) {
			return 'hung';
		}
		posix_kill( $pid, defined( 'SIGTERM' ) ? SIGTERM : 15 );
		Module::warn( 'Product picture runner stopped by the watchdog: no beat in ' . intdiv( self::STALL_S, 60 ) . ' minutes', [ 'pid' => $pid, 'beat_at' => Module::state( self::STATE )['beat_at'] ?? '' ] );
		return 'stopped';
	}

	/** A runner holds the lock but hasn't beaten in STALL_S (counted from its start when it hasn't beaten yet). */
	private static function hung(): bool {
		$s    = Module::state( self::STATE );
		$last = max( strtotime( (string) ( $s['beat_at'] ?? '' ) . ' UTC' ) ?: 0, strtotime( (string) ( $s['started_at'] ?? '' ) . ' UTC' ) ?: 0 );
		return $last && time() - $last > self::STALL_S;
	}

	/** A runner was started under START_S ago and hasn't taken the lock yet (a WP-CLI boot takes a few seconds). */
	private static function starting(): bool {
		$k = strtotime( (string) ( Module::state( self::STATE )['kicked_at'] ?? '' ) . ' UTC' ) ?: 0;
		return time() - $k < self::START_S && ! self::runner_alive();
	}

	/* ── Planning ──────────────────────────────────────────────────────── */

	/** The product's pictures now, featured first. @return int[] */
	public static function current( int $id ): array {
		return PageEvents::pictures( $id );
	}

	/**
	 * What would happen to a product. Reads stored pages only.
	 *
	 * $review: a person reviewing its pictures (Review pictures, queue source REVIEW): pictures already written or
	 * picked by a person are merged again with the page's and the person's choices (vetoes, featured), and a page
	 * with no usable pictures leaves its own set to those choices.
	 *
	 * @return array{mode:string,reason:string,urls:string[],page:string,current:int[]}
	 *         mode vendor | in_place | skip
	 */
	public static function plan( int $id, bool $review = false ): array {
		global $wpdb;
		$out = [ 'mode' => 'skip', 'reason' => '', 'urls' => [], 'page' => '', 'current' => [] ];
		if ( get_post_type( $id ) !== 'product' || get_post_status( $id ) !== 'publish' || Exclusions::is_excluded( $id ) ) {
			return [ 'reason' => 'not_eligible' ] + $out;
		}
		$out['current'] = self::current( $id );
		$picked         = self::picked( $id );
		if ( $picked ) {
			return [ 'mode' => 'vendor', 'reason' => 'person_picked', 'urls' => $picked['urls'], 'page' => $picked['page'] ] + $out;
		}
		if ( ! $out['current'] ) {
			// First pictures from the distributor's data, before any vendor page is read (owner 2026-10-29: "start the
			// product data journey there and utilize the scraping platform when it is needed only after").
			$feed = self::feed_pictures( $id );
			if ( $feed ) {
				return [ 'mode' => 'vendor', 'reason' => $feed[0], 'urls' => $feed[1], 'page' => $feed[2] ] + $out;
			}
			// No distributor pictures: any usable structured picture on the maker's page beats none.
			[ $why, $urls, $page, $imgs ] = self::vendor_pictures( $id, 1 ) + [ 3 => [] ];
			if ( $why === '' ) {
				return [ 'mode' => 'vendor', 'urls' => $urls, 'page' => $page ] + $out;
			}
			if ( $why === 'page_not_read' ) {
				return [ 'reason' => $why, 'page' => $page ] + $out; // the runner reads it first, then plans again
			}
			// None the page could give as it is: the fallbacks, best first.
			$fallback = self::fallback_pictures( $id, $imgs, $page );
			if ( $fallback ) {
				return [ 'mode' => 'vendor', 'reason' => $fallback[0], 'urls' => $fallback[1], 'page' => $fallback[2] ] + $out;
			}
			return [ 'reason' => $why === 'page_not_ok' ? $why : 'no_pictures', 'page' => $page ] + $out;
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$wpdb->prefix}mmi_image_trim_products WHERE product_id = %d", $id ) ) === 'excluded' ) {
			return [ 'reason' => 'kept_by_person' ] + $out;
		}
		$why = self::vendor_block( $id );
		if ( $review && in_array( $why, [ 'vendor_done', 'person_picked' ], true ) ) {
			$why = '';
		}
		if ( $why === '' ) {
			[ $why, $urls, $page ] = self::vendor_pictures( $id );
			if ( $why === '' ) {
				return [ 'mode' => 'vendor', 'urls' => $urls, 'page' => $page ] + $out;
			}
			if ( $review && $why !== 'page_not_read' ) {
				return [ 'mode' => 'vendor', 'reason' => $why, 'urls' => [], 'page' => $page ] + $out; // its own pictures, as the person's choices leave them
			}
		}
		$out['reason'] = $why;
		foreach ( $out['current'] as $a ) {
			if ( ! ImageConvert::is_standard( $a ) ) {
				return [ 'mode' => 'in_place' ] + $out;
			}
		}
		return [ 'reason' => 'already_standard' ] + $out;
	}

	/** Why vendor pictures may not replace this product's set, or ''. */
	private static function vendor_block( int $id ): string {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT status, reviewed_by, chosen FROM ' . Enrichment::table() . " WHERE product_id = %d AND kind = 'image'", $id ), ARRAY_A );
		if ( $row && $row['status'] === 'applied' && ( (int) $row['reviewed_by'] || (string) $row['chosen'] !== '' ) ) {
			return 'person_picked';
		}
		$done = $wpdb->get_var( $wpdb->prepare( 'SELECT mode FROM ' . self::table() . " WHERE product_id = %d AND status = 'applied'", $id ) );
		return $done === 'vendor' ? 'vendor_done' : '';
	}

	/** [ why ('' = usable), picture URLs, page URL ] from the stored vendor page; $min usable pictures needed. */
	private static function vendor_pictures( int $id, int $min = self::MIN_AUTO ): array {
		global $wpdb;
		$ok  = in_array( strtoupper( (string) get_post_meta( $id, 'vendor_product_url_verified', true ) ), [ 'TRUE', '1' ], true ); // stored as TRUE, true and 1
		$url = $ok ? (string) get_post_meta( $id, 'vendor_product_url', true ) : '';
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return [ 'no_verified_page', [], '' ];
		}
		$pg = $wpdb->get_row( $wpdb->prepare( 'SELECT status, html_file FROM ' . PageStore::table() . ' WHERE url_hash = %s', md5( $url ) ), ARRAY_A );
		if ( ! $pg ) {
			return [ 'page_not_read', [], $url ];
		}
		if ( $pg['status'] !== 'ok' ) {
			return [ 'page_not_ok', [], $url ];
		}
		$html = ProductPage::own_html( $id, $url, PageStore::read( (string) $pg['html_file'] ) ); // a page listing several products: its own card only
		if ( $html === null ) {
			return [ 'page_no_card', [], $url ];
		}
		$x    = VendorPage::extract( $html, $url, html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
		if ( in_array( 'page_version_differs', (array) $x['flags'], true ) ) {
			return [ 'page_other_version', [], $url ];
		}
		$imgs = ChoiceBundles::without_cards( $id, $url, $html, (array) $x['images'] ); // a choice bundle's grid is other products
		if ( ! $imgs ) {
			return [ 'page_no_pictures', [], $url ];
		}
		$structured = array_values( array_filter( $imgs, static fn( $i ) => in_array( $i['source'], self::STRUCTURED, true ) ) );
		$usable     = array_values( array_filter( $structured, static fn( $i ) => empty( $i['low_res'] ) ) );
		// The page's pictures go back too (absolute, at their largest), for fallback_pictures().
		$found = array_map( static fn( $i ) => [ 'url' => Enrichment::largest_variant( (string) $i['url'], $html ) ] + $i, $imgs );
		if ( ! $structured ) {
			return [ 'page_share_only', [], $url, $found ];
		}
		if ( ! $usable ) {
			return [ 'page_low_res', [], $url, $found ];
		}
		if ( count( $usable ) < $min ) {
			return [ 'page_one_picture', [], $url, $found ];
		}
		$urls = array_map( static fn( $i ) => Enrichment::largest_variant( (string) $i['url'], $html ), $usable );
		return [ '', array_values( array_unique( $urls ) ), $url ];
	}

	/**
	 * First pictures for a product with none whose vendor page gave none it could use, best first
	 * (owner 2026-10-28): the page's small structured pictures (never discarded for size, D8), its share
	 * image, then the distributors' pictures. Only ever for a product with no picture, so nothing is
	 * replaced; a maker picture found later still merges in.
	 *
	 * @param array $imgs The vendor page's pictures as vendor_pictures() read them.
	 * @return array{0:string,1:string[],2:string}|null [ reason, picture URLs, where they came from ]
	 */
	public static function fallback_pictures( int $id, array $imgs, string $page ): ?array {
		$structured = array_filter( $imgs, static fn( $i ) => in_array( $i['source'] ?? '', self::STRUCTURED, true ) );
		if ( $structured ) {
			return [ 'first_low_res', array_values( array_unique( array_column( $structured, 'url' ) ) ), $page ];
		}
		$share = array_filter( $imgs, static fn( $i ) => ( $i['source'] ?? '' ) === 'og' && ! preg_match( '/logo|icon|favicon|placeholder|default/i', (string) $i['url'] ) && self::share_image_pages( $page, (string) $i['url'] ) < self::SITE_WIDE_SHARE );
		if ( $share ) {
			return [ 'first_share', [ (string) reset( $share )['url'] ], $page ];
		}
		return self::feed_pictures( $id );
	}

	/**
	 * The distributors' pictures of a product: Plugivery's (its own listing, or the same maker's product of the
	 * same name), then XChange's web assets and feed. [ reason, picture URLs, where they came from ] or null.
	 */
	public static function feed_pictures( int $id ): ?array {
		$plugivery = self::plugivery_pictures( $id );
		if ( $plugivery ) {
			return [ 'first_plugivery', $plugivery[0], $plugivery[1] ];
		}
		$xchange = trim( (string) get_post_meta( $id, '_mmi_supplier_sku_xchange', true ) );
		if ( $xchange !== '' ) {
			$urls = (array) ( Enrichment::web_assets_media()[ $xchange ]['images'] ?? [] );
			if ( ! $urls && class_exists( '\MMI_Pipeline_Feed_Catalog' ) && \MMI_Pipeline_Feed_Catalog::get( 'xchange' ) ) {
				foreach ( \MMI_Pipeline_Feed_Catalog::get( 'xchange' )->index()['rows'] ?? [] as $row ) {
					if ( (string) $row['sku'] === $xchange && ! empty( $row['image'] ) ) {
						$urls = [ (string) $row['image'] ];
						break;
					}
				}
			}
			if ( $urls ) {
				return [ 'first_xchange', array_values( $urls ), '' ];
			}
		}
		return null;
	}

	/**
	 * How many of the site's stored pages give this share image: a site-wide banner (Moog's ogimage3.jpg on
	 * every product page) isn't a picture of the product. Counted once a day per site, from stored pages only.
	 */
	private static function share_image_pages( string $page, string $image ): int {
		global $wpdb;
		$host = strtolower( (string) wp_parse_url( $page, PHP_URL_HOST ) );
		if ( $host === '' ) {
			return 0;
		}
		$key    = 'mmpt_share_images_' . md5( $host );
		$counts = get_transient( $key );
		if ( ! is_array( $counts ) ) {
			$counts = [];
			$rows   = $wpdb->get_col( $wpdb->prepare( 'SELECT html_file FROM ' . PageStore::table() . " WHERE host = %s AND status = 'ok' AND html_file <> '' LIMIT 3000", $host ) );
			foreach ( $rows as $file ) {
				if ( preg_match( '#<meta[^>]+(?:property|name)=["\']og:image["\'][^>]*content=["\']([^"\']+)#i', PageStore::read( (string) $file ), $m ) ) {
					$og            = html_entity_decode( $m[1], ENT_QUOTES );
					$counts[ $og ] = ( $counts[ $og ] ?? 0 ) + 1;
				}
			}
			set_transient( $key, $counts, DAY_IN_SECONDS );
		}
		return (int) ( $counts[ html_entity_decode( $image, ENT_QUOTES ) ] ?? 0 );
	}

	/**
	 * The product's Plugivery ID: its own Plugivery listing, else the same maker's Plugivery product of
	 * exactly the same name (only when there is one such product; several is a guess). '' when none.
	 */
	public static function plugivery_match( int $id ): string {
		if ( ! class_exists( '\MMI_Plugivery_Catalog' ) ) {
			return '';
		}
		static $by_name = null;
		$details = \MMI_Plugivery_Catalog::details();
		$pv      = trim( (string) get_post_meta( $id, '_mmi_supplier_sku_plugivery', true ) );
		if ( $pv !== '' && isset( $details[ $pv ] ) ) {
			return $pv;
		}
		$brands = wp_get_post_terms( $id, 'product_brand', [ 'fields' => 'names' ] );
		$brand  = is_array( $brands ) && $brands ? html_entity_decode( (string) $brands[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
		if ( $brand === '' ) {
			return '';
		}
		if ( $by_name === null ) {
			$by_name = [];
			foreach ( $details as $pid => $d ) {
				$by_name[ self::name_key( (string) ( $d['brand_name'] ?? '' ) ) . '|' . self::name_key( (string) ( $d['name'] ?? '' ) ) ][] = (string) $pid;
			}
		}
		$found = $by_name[ self::name_key( $brand ) . '|' . self::name_key( PageName::core( get_the_title( $id ), $brand ) ) ] ?? [];
		return count( $found ) === 1 ? $found[0] : '';
	}

	/** Plugivery's pictures of a product (main first, then box, GUI, home). [ URLs, its plugivery.com page ] or null. */
	private static function plugivery_pictures( int $id ): ?array {
		$pv = self::plugivery_match( $id );
		$p  = $pv !== '' ? ( \MMI_Plugivery_Catalog::details()[ $pv ] ?? null ) : null;
		if ( ! $p ) {
			return null;
		}
		$images = (array) ( $p['images'] ?? [] );
		usort( $images, static fn( $a, $b ) => ( Enrichment::PLUGIVERY_IMAGE_ORDER[ $a['type'] ?? '' ] ?? 9 ) <=> ( Enrichment::PLUGIVERY_IMAGE_ORDER[ $b['type'] ?? '' ] ?? 9 ) );
		$urls = array_values( array_unique( array_filter( array_merge( [ (string) ( $p['img'] ?? '' ) ], array_map( static fn( $i ) => (string) ( $i['url'] ?? '' ), $images ) ) ) ) );
		return $urls ? [ $urls, (string) ( $p['url'] ?? '' ) ] : null;
	}

	/** A name compared without case, punctuation, spaces or a company's legal form. */
	private static function name_key( string $name ): string {
		$key = preg_replace( '/[^a-z0-9]+/', ' ', strtolower( remove_accents( $name ) ) );
		$key = preg_replace( '/\b(ltd|limited|inc|llc|gmbh|corp|corporation|co|sl|ab|as|srl|bv)\b/', ' ', (string) $key );
		return str_replace( ' ', '', (string) $key );
	}

	/* ── Pictures a person picked on the page ─────────────────────────── */

	/**
	 * Queue the pictures a person picked on product $id's page (the Chrome extension) for the runner, ahead of other
	 * work (source manual). @return array{urls:string[],queued:bool}
	 */
	public static function pick( int $id, array $urls, string $page ): array {
		$urls = array_slice( array_values( array_unique( array_filter( array_map( static fn( $u ) => esc_url_raw( trim( (string) $u ), [ 'http', 'https' ] ), $urls ) ) ) ), 0, self::MAX_PICKED );
		if ( ! $urls ) {
			return [ 'urls' => [], 'queued' => false ];
		}
		update_post_meta( $id, self::PICKED_META, [ 'urls' => $urls, 'page' => esc_url_raw( $page ), 'at' => current_time( 'mysql', true ), 'by' => get_current_user_id() ] );
		$queued = self::enqueue( [ $id ], 'manual', true ) > 0;
		return [ 'urls' => $urls, 'queued' => $queued ];
	}

	/** The person's picks the runner hasn't handled yet (it handled the product after they were made: none). @return array{urls:string[],page:string,at:string}|null */
	public static function picked( int $id ): ?array {
		$p = get_post_meta( $id, self::PICKED_META, true );
		if ( ! is_array( $p ) || empty( $p['urls'] ) || empty( $p['at'] ) ) {
			return null;
		}
		global $wpdb;
		$t   = self::table();
		$ran = (string) $wpdb->get_var( $wpdb->prepare( "SELECT run_at FROM {$t} WHERE product_id = %d AND status IN ('applied','skipped','error','reverted')", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		return $ran !== '' && strtotime( $ran . ' UTC' ) >= strtotime( (string) $p['at'] . ' UTC' ) ? null : [ 'urls' => array_values( (array) $p['urls'] ), 'page' => (string) ( $p['page'] ?? '' ), 'at' => (string) $p['at'] ];
	}

	/* ── The queue ─────────────────────────────────────────────────────── */

	/**
	 * Queue products. Waiting rows keep their place; finished rows (skipped,
	 * error, reverted) are queued again; applied ones only with $again.
	 * @return int products now waiting from this call
	 */
	public static function enqueue( array $ids, string $source = 'manual', bool $again = false ): int {
		self::maybe_install();
		global $wpdb;
		$ids = Exclusions::filter( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ) );
		$t   = self::table();
		$now = current_time( 'mysql', true );
		$n   = 0;
		$keep = $again ? "'queued','working','revert'" : "'queued','working','revert','applied'";
		// Products with no picture first (a shop placeholder), then heaviest first, when measuring is cheap enough
		// here: a web request queuing thousands doesn't stat every file (the runner then goes in queue order).
		$weigh = count( $ids ) <= 500 || ( defined( 'WP_CLI' ) && WP_CLI );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$values = [];
			foreach ( $chunk as $id ) {
				$cur      = $weigh ? self::current( $id ) : [];
				$values[] = $wpdb->prepare( '(%d,%s,%s,%d,%s)', $id, 'queued', $source, $weigh ? ( $cur ? self::bytes_of( $cur ) : PHP_INT_MAX ) : 0, $now );
			}
			$n += (int) $wpdb->query(
				"INSERT INTO {$t} (product_id, status, source, priority, queued_at) VALUES " . implode( ',', $values ) . "
				 ON DUPLICATE KEY UPDATE
				   source = IF( status IN ({$keep}), source, VALUES(source) ),
				   priority = IF( status IN ({$keep}), priority, VALUES(priority) ),
				   mode = IF( status IN ({$keep}), mode, '' ),
				   queued_at = IF( status IN ({$keep}), queued_at, VALUES(queued_at) ),
				   status = IF( status IN ({$keep}), status, 'queued' )" // phpcs:ignore WordPress.DB.PreparedSQL
			) ? count( $chunk ) : 0;
		}
		$waiting = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'queued'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $waiting && in_array( $source, [ 'manual', self::REVIEW ], true ) ) {
			$pass = (array) ( Module::state( self::STATE )['pass'] ?? [] );
			if ( empty( $pass['started_at'] ) || ! empty( $pass['done_at'] ) ) {
				$pass = [ 'started_at' => current_time( 'mysql', true ), 'done_at' => '', 'by' => get_current_user_id() ];
			}
			Module::set_state( self::STATE, [ 'pass' => $pass ] );
		}
		Module::info( 'Product pictures queued', [ 'asked' => count( $ids ), 'waiting' => $waiting, 'source' => $source ] );
		return $n;
	}

	/** Drop rows that haven't started. @return int */
	public static function clear_pending(): int {
		self::maybe_install();
		global $wpdb;
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() . " WHERE status = 'queued' AND run_at IS NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * The day's product cap and Claude calls: DAILY_CAP and PictureMatch::CALLS_DAY, or a pass's own limits
	 * (LIMITS_KEY) until their `until` (UTC) passes; then the standing limits apply again by themselves.
	 * @return array{cap:int,calls:int}
	 */
	public static function limits(): array {
		$o  = (array) \MMI_Settings::get( self::LIMITS_KEY, [] );
		$on = ! empty( $o['until'] ) && strtotime( (string) $o['until'] . ' UTC' ) > time();
		return [
			'cap'   => $on && isset( $o['cap'] ) ? (int) $o['cap'] : self::DAILY_CAP,
			'calls' => $on && isset( $o['calls'] ) ? (int) $o['calls'] : PictureMatch::CALLS_DAY,
		];
	}

	/** Products changed in the last 24 hours (the daily cap counts both modes and reverts). */
	public static function done_today(): int {
		self::maybe_install();
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() . " WHERE status IN ('applied','reverted') AND run_at > UTC_TIMESTAMP() - INTERVAL 1 DAY" ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function runner_alive(): bool {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', self::LOCK ) ) !== null;
	}

	/** Whether a pass a person started is still open (queued products left). */
	public static function pass_open(): bool {
		$pass = (array) ( Module::state( self::STATE )['pass'] ?? [] );
		return ! empty( $pass['started_at'] ) && empty( $pass['done_at'] );
	}

	/**
	 * Start the runner, detached, when there is work it may do now. $auto (the
	 * watchdog) only finishes an open pass and due quarantine; it never starts
	 * a new pass. True while one started under START_S ago is still booting.
	 */
	public static function kick( bool $auto = false ): bool {
		self::maybe_install();
		global $wpdb;
		if ( self::runner_alive() ) {
			return false;
		}
		if ( self::starting() ) {
			return true;
		}
		$t       = self::table();
		$revert  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'revert'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$waiting = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'queued'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$due     = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . MediaRelease::table() . " WHERE state = 'released' AND release_after <= UTC_TIMESTAMP()" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$first = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'queued' AND source = 'first'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		$queue = $waiting && self::done_today() < self::limits()['cap'] && ( ! $auto || self::pass_open() || $first );
		if ( ! $revert && ! $due && ! $queue ) {
			return false;
		}
		$ok = Scraper::spawn( 'mmpt images run', 'pictures-runner.log' );
		if ( $ok ) {
			Module::set_state( self::STATE, [ 'kicked_at' => current_time( 'mysql', true ) ] );
		}
		return $ok;
	}

	/**
	 * First pictures (automatic, see the class note): products with no picture,
	 * a verified URL and never in the ledger — or left alone earlier because
	 * their page wasn't read or had nothing, and read again since. @return int queued
	 */
	public static function queue_first( int $max = 500 ): int {
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$pg  = PageStore::table();
		$ids = array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url' AND LEFT( u.meta_value, 4 ) = 'http'
					 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
					 LEFT JOIN {$wpdb->postmeta} th ON th.post_id = p.ID AND th.meta_key = '_thumbnail_id'
					 LEFT JOIN {$t} l ON l.product_id = p.ID
					 LEFT JOIN {$pg} pg ON pg.url_hash = MD5( u.meta_value )
					 WHERE p.post_type = 'product' AND p.post_status = 'publish' AND COALESCE( th.meta_value, '' ) IN ('', '0')
					   AND ( l.product_id IS NULL OR ( l.status = 'skipped' AND l.reason IN ('no_pictures','page_not_read','page_not_ok') AND pg.fetched_at > l.run_at ) )" . Exclusions::sql_not_excluded( 'p.ID' ) . '
					 LIMIT %d',
					$max
				)
			)
		);
		return $ids ? self::enqueue( $ids, 'first' ) : 0;
	}

	/* ── The runner ────────────────────────────────────────────────────── */

	/**
	 * WP-CLI. Reverts first, then quarantine that is due, then the queue up to the daily cap. With $budget (seconds, a
	 * Background step) it runs in a web request too, never sleeps, and says whether work is left (more, busy).
	 */
	public static function run( int $limit = 0, int $budget = 0 ): array {
		if ( ! $budget && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return [ 'error' => 'Runs from WP-CLI only (wp mmpt images run).' ];
		}
		self::maybe_install();
		global $wpdb;
		if ( ! (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::LOCK ) ) ) {
			return [ 'skipped' => 'another runner is working' ];
		}
		$t     = self::table();
		$start = time();
		$out   = [ 'vendor' => 0, 'in_place' => 0, 'skipped' => 0, 'error' => 0, 'later' => 0, 'reverted' => 0, 'bytes_before' => 0, 'bytes_after' => 0 ];
		$beat  = static fn( string $note = '' ) => Module::set_state( self::STATE, [ 'beat_at' => current_time( 'mysql', true ), 'note' => $note ] );
		Module::set_state( self::STATE, [ 'started_at' => current_time( 'mysql', true ), 'finished_at' => '', 'note' => '', 'pid' => getmypid() ] );

		// Rows a killed runner left mid-product (the daily reboot): this runner holds the lock, so none is really working.
		$wpdb->query( "UPDATE {$t} SET status = 'queued' WHERE status = 'working'" ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( array_map( 'intval', $wpdb->get_col( "SELECT product_id FROM {$t} WHERE status = 'revert'" ) ) as $id ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$r = self::revert( $id );
			$out[ $r['result'] === 'reverted' ? 'reverted' : 'error' ]++;
		}
		$q    = MediaRelease::quarantine_due();
		$done = 0;
		$max  = $budget ?: self::MAX_RUN_S;
		$busy = false;
		$more = false;
		while ( time() - $start < $max && ( ! $limit || $done < $limit ) ) {
			// A deploy: this process still runs the code it started with (2026-10-31, a runner kept a fixed bug for hours).
			// It stops, and the watchdog starts one on the new code within a minute.
			if ( PageEvents::code_changed() ) {
				$beat( 'A new version was deployed; a fresh runner carries on' );
				break;
			}
			$act = Activity::check();
			if ( $act['manual'] ) {
				$beat( 'Paused: ' . implode( '; ', $act['reasons'] ) );
				if ( $budget ) {
					$busy = true;
					$more = true;
					break;
				}
				sleep( self::PAUSE_S );
				continue;
			}
			$cap = self::limits()['cap'];
			if ( self::done_today() >= $cap ) {
				$beat( 'Daily limit reached (' . $cap . ' products in 24 hours); carries on later' );
				break;
			}
			$id = (int) $wpdb->get_var( "SELECT product_id FROM {$t} WHERE status = 'queued' ORDER BY " . self::QUEUE_ORDER . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $id ) {
				$beat();
				if ( self::pass_open() ) {
					$pass = [ 'done_at' => current_time( 'mysql', true ) ] + (array) Module::state( self::STATE )['pass'];
					Module::set_state( self::STATE, [ 'pass' => $pass ] );
					Module::info( 'Product picture pass complete; the runner starts again only when products are queued by hand', $pass );
				}
				break;
			}
			if ( ! $wpdb->update( $t, [ 'status' => 'working' ], [ 'product_id' => $id, 'status' => 'queued' ] ) ) {
				continue; // stopped meanwhile
			}
			try {
				$r = self::process( $id );
			} catch ( \Throwable $e ) {
				$r = [ 'result' => 'error' ];
				$wpdb->update( $t, [ 'status' => 'error', 'note' => mb_substr( $e->getMessage(), 0, 255 ), 'run_at' => current_time( 'mysql', true ) ], [ 'product_id' => $id ] );
				Module::warn( 'Product pictures failed', [ 'product_id' => $id, 'error' => $e->getMessage() ] );
			}
			$res         = $r['result'] === 'applied' ? (string) $r['mode'] : (string) $r['result'];
			$out[ $res ] = ( $out[ $res ] ?? 0 ) + 1;
			$out['bytes_before'] += (int) ( $r['bytes_before'] ?? 0 );
			$out['bytes_after']  += (int) ( $r['bytes_after'] ?? 0 );
			$done++;
			$beat( $act['busy'] ? 'Slowed: ' . implode( '; ', $act['reasons'] ) : '' );
			if ( $act['busy'] || $r['result'] === 'later' ) { // 'later': an AVIF waits for Chromium; don't spin on it
				if ( $budget ) {
					$busy = true;
					break;
				}
				sleep( self::SLOW_S );
			}
		}
		if ( $budget && ! $more ) {
			$more = self::done_today() < self::limits()['cap'] && (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('queued','revert')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$out['quarantined'] = $q['quarantined'];
		$out['kept']        = $q['kept'];
		Module::set_state( self::STATE, [ 'finished_at' => current_time( 'mysql', true ), 'last_run' => $out ] );
		Module::info( 'Product picture runner finished', $out );
		if ( $out['in_place'] ) {
			// Owner D7: say plainly which products didn't get vendor pictures.
			Module::warn( sprintf( 'NOTICE: %d product(s) kept their own pictures, re-saved to the 800×800 standard, because their vendor page had no usable pictures. The ledger (Vendor pages tab → Product pictures, filter "Own pictures") lists each with the reason.', $out['in_place'] ), [] );
		}
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		return $budget ? $out + [ 'more' => $more, 'busy' => $busy ] : $out;
	}

	/**
	 * One product: plan, write, record, release. @return array{result:string,mode?:string,bytes_before?:int,bytes_after?:int}
	 * result applied | skipped | later | error
	 */
	public static function process( int $id, bool $dry = false, bool $own = false ): array { // $own: apply_now(), a person's choices on the pictures it has: nothing is downloaded
		global $wpdb;
		$t      = self::table();
		$prev   = $wpdb->get_row( $wpdb->prepare( "SELECT source, old_thumb, old_gallery, new_ids, locked_by_us, map, reverted_at FROM {$t} WHERE product_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$review = $own || ( $prev['source'] ?? '' ) === self::REVIEW;
		$plan   = self::plan( $id, $review );
		if ( ! $dry && in_array( $plan['reason'], [ 'page_not_read', 'page_not_ok' ], true ) ) {
			// Read the vendor page first (the nightly read's pacing, robots.txt and 30-day reuse),
			// so a product isn't re-saved in place only because nobody had read its page yet.
			Scraper::process_one( $id, 'read', false );
			$plan = self::plan( $id, $review );
		}
		if ( $own ) {
			$plan = $plan['mode'] === 'vendor' ? [ 'urls' => [] ] + $plan : [ 'mode' => 'skip', 'reason' => $plan['reason'] !== '' ? $plan['reason'] : 'not_eligible' ] + $plan; // its own set as the choices leave it; never re-saved in place here
		}
		$now  = current_time( 'mysql', true );
		// Queued for the page's pictures only (`images queue --vendor-only`): never re-saved in place instead.
		if ( ! $dry && $plan['mode'] !== 'vendor' && $wpdb->get_var( $wpdb->prepare( "SELECT mode FROM {$t} WHERE product_id = %d", $id ) ) === 'vendor' ) {
			$plan = [ 'mode' => 'skip', 'reason' => $plan['reason'] !== '' ? $plan['reason'] : 'not_eligible' ] + $plan;
		}
		if ( $plan['mode'] === 'skip' ) {
			if ( ! $dry ) {
				$wpdb->update( $t, [ 'status' => 'skipped', 'mode' => '', 'reason' => $plan['reason'], 'run_at' => $now, 'pics_before' => count( $plan['current'] ) ], [ 'product_id' => $id ] );
			}
			return [ 'result' => 'skipped', 'reason' => $plan['reason'] ];
		}
		if ( $dry ) {
			$merge = $plan['mode'] === 'vendor' ? PictureMatch::summary( PictureMatch::merge( $plan['current'], $plan['urls'], false, $id ) ) : '';
			return [ 'result' => 'plan', 'mode' => $plan['mode'], 'reason' => $plan['reason'], 'urls' => $plan['urls'], 'merge' => $merge, 'pics_before' => count( $plan['current'] ), 'bytes_before' => self::bytes_of( $plan['current'] ) ];
		}
		$old    = $plan['current'];
		$mode   = $plan['mode'];
		$reason = $plan['reason'];
		$map    = [];
		$new    = [];
		$notes  = [];
		$made   = []; // attachments this run imported
		$why    = []; // released attachment => reason
		$merge  = null;
		if ( $mode === 'vendor' ) {
			$merge = PictureMatch::merge( $old, $plan['urls'], ! $own, $id ); // with a person's vetoes and featured choice (PictureChoices)
			if ( $old && ! $merge['changed'] ) {
				// Nothing to add: the product's own pictures stay exactly as they are (owner 2026-10-21: never replaced).
				$wpdb->update( $t, [ 'status' => 'skipped', 'mode' => '', 'reason' => 'nothing_to_add', 'run_at' => $now, 'pics_before' => count( $old ) ], [ 'product_id' => $id ] );
				return [ 'result' => 'skipped', 'reason' => 'nothing_to_add' ];
			}
		}
		if ( $mode === 'vendor' ) {
			$title  = PageName::clean( html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			$failed = 0;
			$later  = static function () use ( &$made, $id, $wpdb, $t ) {
				foreach ( $made as $a ) {
					MediaRelease::release( $a, [ $id ], 'picture run interrupted; not used' );
				}
				$wpdb->update( $t, [ 'status' => 'queued' ], [ 'product_id' => $id ] );
				return [ 'result' => 'later' ];
			};
			// The product's own pictures stay exactly as they are: never swapped for a re-encoded copy
			// (owner 2026-10-21: "do not replace existing valid images"). Only maker pictures added now are made standard.
			$keep = static function ( int $a ) use ( &$new ) {
				$new[] = $a;
				return true;
			};
			foreach ( $merge['final'] as $f ) {
				if ( isset( $f['att'] ) ) {
					if ( ! $keep( (int) $f['att'] ) ) {
						return $later();
					}
					continue;
				}
				$u   = (string) $f['url'];
				$att = self::standard_by_source( $u );
				if ( ! $att ) {
					$host = strtolower( (string) wp_parse_url( $u, PHP_URL_HOST ) );
					\MMI_API_Throttler::throttle( VendorFetcher::throttle_key( $host ), [ 'min_gap_ms' => PageStore::policy( $host )['gap_ms'] ] );
					// Plugivery's image servers publish IPv6 addresses this server can't reach.
					$ipv4 = static function ( $handle ): void {
						curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 ); // phpcs:ignore WordPress.WP.AlternativeFunctions
					};
					$v4   = (bool) preg_match( '/(^|\.)plugivery\.(net|com)$/', $host );
					$v4 && add_action( 'http_api_curl', $ipv4 );
					$att = ImageConvert::import( $u, $id );
					$v4 && remove_action( 'http_api_curl', $ipv4 );
					if ( $att === 'wait' ) {
						return $later();
					}
					if ( is_wp_error( $att ) ) {
						$notes[] = $att->get_error_message();
						$failed++;
						if ( $f['kind'] === 'sharper' && ! $keep( (int) $f['of'] ) ) { // no sharper copy after all: ours stays
							return $later();
						}
						continue;
					}
					update_post_meta( (int) $att, '_wp_attachment_image_alt', $title );
					$made[] = (int) $att;
				}
				$new[] = (int) $att;
				if ( $f['kind'] === 'sharper' ) {
					$why[ (int) $f['of'] ] = 'replaced by a sharper vendor copy';
				}
			}
			foreach ( $merge['current'] as $c ) {
				if ( $c['kind'] === 'duplicate' ) {
					$why[ (int) $c['att'] ] = 'duplicate of #' . $c['of'];
				} elseif ( $c['kind'] === 'vetoed' ) {
					$why[ (int) $c['att'] ] = 'vetoed by a person';
				}
			}
			$new = array_values( array_unique( $new ) );
			$all_vetoed = ! $new && $old && ! array_filter( $merge['current'], static fn( $c ) => $c['kind'] !== 'vetoed' ) && ! $merge['counts']['new']; // a person took every picture off
			if ( ( ! $new && ! $all_vetoed ) || $new === $old ) {
				$wpdb->update( $t, [ 'status' => 'error', 'mode' => 'vendor', 'reason' => 'vendor_failed', 'run_at' => $now, 'note' => mb_substr( implode( '; ', $notes ) ?: 'nothing could be downloaded', 0, 255 ) ], [ 'product_id' => $id ] );
				return [ 'result' => 'error' ];
			}
			array_unshift( $notes, PictureMatch::summary( $merge ) . ( $failed ? " ({$failed} could not be downloaded)" : '' ) );
		}
		if ( $mode === 'in_place' ) {
			foreach ( $old as $a ) {
				$r = ImageConvert::standardise( $a, $id );
				if ( $r === 'wait' ) {
					$wpdb->update( $t, [ 'status' => 'queued' ], [ 'product_id' => $id ] );
					return [ 'result' => 'later' ];
				}
				if ( is_wp_error( $r ) ) {
					$notes[] = "#{$a}: " . $r->get_error_message();
					$new[]   = $a; // keep it as it is
					continue;
				}
				$n     = $r === 'standard' ? $a : (int) $r;
				$new[] = $n;
				if ( $n !== $a ) {
					$map[ $a ] = $n;
				}
			}
			$new = array_values( array_unique( $new ) );
			if ( ! $map ) {
				$wpdb->update( $t, [ 'status' => 'error', 'mode' => $mode, 'reason' => $reason, 'run_at' => $now, 'note' => mb_substr( implode( '; ', $notes ) ?: 'nothing could be re-saved', 0, 255 ) ], [ 'product_id' => $id ] );
				return [ 'result' => 'error' ];
			}
		}
		$before_gallery = (string) get_post_meta( $id, '_product_image_gallery', true );
		$row            = [
			'status'       => 'working',
			'mode'         => $mode,
			'reason'       => $reason,
			'source_url'   => mb_substr( (string) $plan['page'], 0, 500 ),
			'old_thumb'    => (int) get_post_thumbnail_id( $id ),
			'old_gallery'  => $before_gallery,
			'new_ids'      => wp_json_encode( $new ),
			'map'          => $map ? wp_json_encode( $map ) : '',
			'pics_before'  => count( $old ),
			'pics_after'   => count( $new ),
			'bytes_before' => self::bytes_of( $old ),
			'bytes_after'  => self::bytes_of( $new ),
			'note'         => mb_substr( implode( '; ', $notes ), 0, 255 ),
			'decisions'    => $merge ? wp_json_encode( array_intersect_key( $merge, array_flip( [ 'current', 'page', 'counts', 'doubt', 'asked', 'featured' ] ) ) + [ 'final' => $new ], JSON_UNESCAPED_SLASHES ) : null,
			'run_at'       => $now,
			'reverted_at'  => null,
		];
		// A review's write on top of an earlier one keeps that one's old set: Put back still means "as before our pictures".
		$kept = $review && $prev && $prev['reverted_at'] === null && (string) $prev['new_ids'] !== '' && (string) $prev['new_ids'] !== '[]';
		if ( $kept ) {
			$row['old_thumb']   = (int) $prev['old_thumb'];
			$row['old_gallery'] = (string) $prev['old_gallery'];
			$row['map']         = (string) $prev['map'];
		}
		$wpdb->update( $t, $row, [ 'product_id' => $id ] ); // the old set is on record before anything changes

		// As before the gateway: written over a lock, then the pictures are locked against imports.
		$res = Write::field( $id, '_thumbnail_id', $new ? (int) $new[0] : 0, 'product-pictures', 'force', [ 'lock' => true ] );
		Write::field( $id, '_product_image_gallery', count( $new ) > 1 ? implode( ',', array_slice( $new, 1 ) ) : null, 'product-pictures', 'force' );
		wc_delete_product_transients( $id );
		$locked = empty( $res['lock_added'] ) ? 0 : 1;
		self::follow_picker_row( $id, $mode, $map );
		foreach ( array_diff( $old, $new ) as $a ) {
			MediaRelease::release( $a, [ $id ], $why[ $a ] ?? ( $mode === 'vendor' ? 'replaced by vendor pictures' : 'replaced by its standard copy' ) );
		}
		$wpdb->update( $t, [ 'status' => 'applied', 'locked_by_us' => $locked || ( $kept && (int) $prev['locked_by_us'] ) ? 1 : 0 ], [ 'product_id' => $id ] );
		// mmi-seo writes alt text, title, caption and description from its picture templates (owner
		// 2026-10-08). Pictures imported now carry import text, so it may replace theirs; a re-saved
		// picture keeps text a person wrote.
		do_action( 'mmi_seo_refresh_product_images', $id, $made );
		self::purge_page( $id ); // the product page shows its new pictures now, not after the page cache's 8 hours
		return [ 'result' => 'applied', 'mode' => $mode, 'bytes_before' => $row['bytes_before'], 'bytes_after' => $row['bytes_after'] ];
	}

	/**
	 * The product's picker row follows the change: after vendor pictures it has
	 * nothing left to offer; after in_place its "own pictures" point at the
	 * standard copies.
	 */
	private static function follow_picker_row( int $id, string $mode, array $map ): void {
		global $wpdb;
		$et  = Enrichment::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT status, current_value, chosen FROM {$et} WHERE product_id = %d AND kind = 'image'", $id ), ARRAY_A );
		if ( ! $row || ! in_array( $row['status'], [ 'proposed', 'accepted', 'stale', 'error' ], true ) ) {
			return;
		}
		if ( $mode === 'vendor' ) {
			$wpdb->update( $et, [ 'status' => 'stale' ], [ 'product_id' => $id, 'kind' => 'image' ] );
			return;
		}
		$cur = (array) json_decode( (string) $row['current_value'], true );
		if ( isset( $cur['thumb'] ) ) {
			$cur['thumb']   = $map[ (int) $cur['thumb'] ] ?? (int) $cur['thumb'];
			$cur['gallery'] = array_map( static fn( $g ) => $map[ (int) $g ] ?? (int) $g, (array) ( $cur['gallery'] ?? [] ) );
		}
		$chosen = array_map( static fn( $tok ) => is_string( $tok ) && strpos( $tok, 'att:' ) === 0 && isset( $map[ (int) substr( $tok, 4 ) ] ) ? 'att:' . $map[ (int) substr( $tok, 4 ) ] : $tok, (array) json_decode( (string) $row['chosen'], true ) );
		$wpdb->update( $et, [ 'current_value' => $cur ? wp_json_encode( $cur ) : $row['current_value'], 'chosen' => $chosen ? wp_json_encode( array_values( $chosen ) ) : $row['chosen'] ], [ 'product_id' => $id, 'kind' => 'image' ] );
	}

	/** A standard attachment already made from this source URL, or 0. */
	private static function standard_by_source( string $url ): int {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT s.post_id FROM {$wpdb->postmeta} s JOIN {$wpdb->postmeta} z ON z.post_id = s.post_id AND z.meta_key = '_mmpt_source_size' JOIN {$wpdb->posts} p ON p.ID = s.post_id AND p.post_type = 'attachment'
				 WHERE s.meta_key = %s AND s.meta_value = %s ORDER BY s.post_id DESC LIMIT 1",
				\MMI_Media_Helper::SOURCE_URL_META_KEY,
				trim( $url )
			)
		);
	}

	/** Bytes of these attachments' files, every file counted. */
	public static function bytes_of( array $atts ): int {
		$n = 0;
		foreach ( array_unique( array_map( 'intval', $atts ) ) as $a ) {
			$n += MediaRelease::sum_bytes( MediaRelease::files_of( $a ) );
		}
		return $n;
	}

	/* ── Undo ──────────────────────────────────────────────────────────── */

	/**
	 * Put a product's pictures back. Old pictures already in quarantine come
	 * back first (WP-CLI only; from the web the row waits for the runner).
	 * @return array{result:string,message:string}
	 */
	public static function revert( int $id ): array {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE product_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row || ! in_array( $row['status'], [ 'applied', 'revert' ], true ) ) {
			return [ 'result' => 'error', 'message' => 'Only changed pictures can be put back.' ];
		}
		$old = array_values( array_filter( array_merge( [ (int) $row['old_thumb'] ], array_map( 'intval', explode( ',', (string) $row['old_gallery'] ) ) ) ) );
		foreach ( $old as $a ) {
			if ( get_post_type( $a ) === 'attachment' ) {
				continue;
			}
			if ( ! ( ( defined( 'WP_CLI' ) && WP_CLI ) || Background::$stepping ) ) {
				$wpdb->update( $t, [ 'status' => 'revert' ], [ 'product_id' => $id ] );
				self::kick();
				return [ 'result' => 'queued', 'message' => 'Its old pictures are in quarantine; the background runner puts them back within a minute or two.' ];
			}
			$r = MediaRelease::restore( $a );
			if ( is_wp_error( $r ) ) {
				$wpdb->update( $t, [ 'status' => 'applied', 'note' => mb_substr( 'Revert failed: ' . $r->get_error_message(), 0, 255 ) ], [ 'product_id' => $id ] );
				return [ 'result' => 'error', 'message' => $r->get_error_message() ];
			}
		}
		Write::field( $id, '_thumbnail_id', (int) $row['old_thumb'], 'product-pictures', 'force' ); // our own write, put back
		Write::field( $id, '_product_image_gallery', (string) $row['old_gallery'] === '' ? null : (string) $row['old_gallery'], 'product-pictures', 'force' );
		wc_delete_product_transients( $id );
		if ( (int) $row['locked_by_us'] && class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
			\MMI_Pipeline_Field_Locks::unlock( $id, 'images' );
		}
		foreach ( $old as $a ) {
			MediaRelease::unrelease( $a, 'put back by a revert' );
		}
		$map = array_flip( array_map( 'intval', (array) json_decode( (string) $row['map'], true ) ) );
		if ( $map ) {
			self::follow_picker_row( $id, 'in_place', $map ); // the picker row points at the old pictures again
		}
		foreach ( array_diff( array_map( 'intval', (array) json_decode( (string) $row['new_ids'], true ) ), $old ) as $a ) {
			MediaRelease::release( $a, [ $id ], 'picture written by the runner, reverted' );
		}
		$wpdb->update( $t, [ 'status' => 'reverted', 'reverted_at' => current_time( 'mysql', true ), 'run_at' => current_time( 'mysql', true ) ], [ 'product_id' => $id ] );
		do_action( 'mmi_seo_refresh_product_images', $id, [] ); // positions changed; only text mmi-seo wrote, empty or file names
		Module::info( 'Product pictures put back', [ 'product_id' => $id, 'mode' => $row['mode'] ] );
		return [ 'result' => 'reverted', 'message' => '' ];
	}

	/* ── Catch-up: pictures the replacing pass dropped (owner 2026-10-18) ── */

	/**
	 * Before 2026-10-18 vendor pictures replaced a product's set. For one such
	 * product: each old picture it no longer has, and whether the product still
	 * has that picture in another copy (covered), another old picture is the
	 * same one (duplicate), it is gone (lost: restore it), or the file isn't a
	 * picture (not_a_picture: never restored). Reads quarantined
	 * files in place (WP-CLI); $ask lets PictureMatch ask Claude about doubtful
	 * pairs (within the day's calls). Writes nothing else.
	 *
	 * @return array{id:int,gone:array<int,array{att:int,state:string,file:string,kind:string,of?:int}>,lost:int[]}
	 */
	public static function dropped( int $id, bool $ask = false ): array {
		global $wpdb;
		$out = [ 'id' => $id, 'gone' => [], 'lost' => [] ];
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT old_thumb, old_gallery FROM ' . self::table() . " WHERE product_id = %d AND status = 'applied' AND mode = 'vendor'", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return $out;
		}
		$old  = array_values( array_unique( array_filter( array_merge( [ (int) $row['old_thumb'] ], array_map( 'intval', explode( ',', (string) $row['old_gallery'] ) ) ) ) ) );
		$cur  = self::current( $id );
		$gone = array_values( array_diff( $old, $cur ) );
		if ( ! $gone ) {
			return $out;
		}
		$pics = [];
		foreach ( $cur as $a ) {
			$pics[] = [ 'fp' => PictureMatch::of_attachment( $a ), 'file' => static fn() => (string) get_attached_file( $a ) ];
		}
		$nc = count( $pics );
		foreach ( $gone as $a ) {
			[ $state, $file ] = self::old_file( $a );
			$out['gone'][]    = [ 'att' => $a, 'state' => $state, 'file' => $file, 'kind' => $file === '' ? 'deleted' : 'lost' ];
			$pics[]           = [ 'fp' => $file !== '' ? PictureMatch::of_file( $file ) : null, 'file' => static fn() => $file ];
		}
		$pairs = [];
		for ( $g = $nc; $g < count( $pics ); $g++ ) {
			for ( $j = 0; $j < $g; $j++ ) {
				$pairs[] = [ $j, $g ];
			}
		}
		$d = PictureMatch::decide( $pics, $pairs, $ask );
		foreach ( $out['gone'] as $k => &$g ) {
			if ( $g['kind'] !== 'lost' ) {
				continue;
			}
			$i = $nc + $k;
			for ( $j = 0; $j < $i; $j++ ) {
				if ( empty( $d['same'][ "{$j}:{$i}" ] ) ) {
					continue;
				}
				if ( $j < $nc ) {
					$g['kind'] = 'covered';
					$g['of']   = $cur[ $j ];
					break;
				}
				if ( $out['gone'][ $j - $nc ]['kind'] === 'lost' ) {
					$g['kind'] = 'duplicate';
					$g['of']   = $out['gone'][ $j - $nc ]['att'];
					break;
				}
			}
			if ( $g['kind'] === 'lost' && ! $pics[ $i ]['fp'] ) {
				// Can't be compared: a picture is restored all the same (never lost on a guess); a file
				// that isn't a picture at all (a web page saved as .png by an old import) is not.
				$g['kind'] = @getimagesize( $g['file'] ) ? 'unreadable' : 'not_a_picture'; // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
		unset( $g );
		$out['lost']  = array_values( array_map( static fn( $g ) => $g['att'], array_filter( $out['gone'], static fn( $g ) => in_array( $g['kind'], [ 'lost', 'unreadable' ], true ) ) ) );
		$out['doubt'] = $d['doubt'];
		return $out;
	}

	/** [ state, readable file or '' ] of an old picture: still in the library, released, quarantined or deleted. */
	private static function old_file( int $att ): array {
		global $wpdb;
		$r = $wpdb->get_row( $wpdb->prepare( 'SELECT state, quarantine_dir, files FROM ' . MediaRelease::table() . " WHERE attachment_id = %d AND kind = 'attachment'", $att ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( get_post_type( $att ) === 'attachment' ) {
			$f = (string) get_attached_file( $att );
			return [ $r ? (string) $r['state'] : 'library', is_file( $f ) ? $f : '' ];
		}
		if ( $r && $r['state'] === 'quarantined' ) {
			foreach ( (array) ( json_decode( (string) $r['files'], true )['rel'] ?? [] ) as $rel ) {
				$f = rtrim( (string) $r['quarantine_dir'], '/' ) . '/files/' . $rel;
				if ( is_file( $f ) && ! preg_match( '/-\d+x\d+\.[a-z]+$/i', $rel ) ) { // the original, not a generated size
					return [ 'quarantined', $f ];
				}
			}
		}
		return [ $r ? (string) $r['state'] : 'unknown', '' ];
	}

	/**
	 * Put a product's lost pictures back (WP-CLI): restored from quarantine,
	 * re-saved to the standard, added after its pictures up to the cap (the rest
	 * stay in quarantine, listed). The ledger row records them, so a revert
	 * still puts the product back as it was before any pass.
	 *
	 * @return array{restored:int[],left:int[],errors:string[]}
	 */
	public static function restore_dropped( int $id, array $lost ): array {
		global $wpdb;
		$out = [ 'restored' => [], 'left' => [], 'errors' => [] ];
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			$out['errors'][] = 'Runs from WP-CLI only.';
			return $out;
		}
		$cur  = self::current( $id );
		$add  = [];
		foreach ( array_map( 'intval', $lost ) as $a ) {
			if ( get_post_type( $a ) !== 'attachment' ) {
				$r = MediaRelease::restore( $a );
				if ( is_wp_error( $r ) ) {
					$out['errors'][] = "#{$a}: " . $r->get_error_message();
					continue;
				}
			} else {
				MediaRelease::unrelease( $a, 'restored by the 2026-10-18 catch-up' );
			}
			$std = ImageConvert::standardise( $a, $id );
			$n   = is_int( $std ) || ( is_string( $std ) && ctype_digit( $std ) ) ? (int) $std : $a; // 'standard', 'wait' or an error: the picture as it is
			if ( $n !== $a ) {
				MediaRelease::release( $a, [ $id ], 'replaced by its standard copy' );
			}
			$add[]             = $n;
			$out['restored'][] = $a;
		}
		if ( ! $add ) {
			return $out;
		}
		$all = array_merge( $cur, $add );
		Write::field( $id, '_thumbnail_id', (int) $all[0], 'product-pictures', 'force' );
		Write::field( $id, '_product_image_gallery', implode( ',', array_slice( $all, 1 ) ), 'product-pictures', 'force' );
		wc_delete_product_transients( $id );
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT new_ids, note, decisions FROM {$t} WHERE product_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$dec = (array) json_decode( (string) ( $row['decisions'] ?? '' ), true );
		$dec['restored'] = [ 'at' => current_time( 'mysql', true ), 'old' => $out['restored'], 'new' => $add ];
		$wpdb->update(
			$t,
			[
				'new_ids'    => wp_json_encode( array_values( array_unique( array_merge( array_map( 'intval', (array) json_decode( (string) $row['new_ids'], true ) ), $add ) ) ) ),
				'pics_after' => count( $all ),
				'note'       => mb_substr( trim( 'Restored ' . count( $add ) . ' dropped ' . _n( 'picture', 'pictures', count( $add ), 'mmi-data-pipeline' ) . ' (2026-10-18 catch-up). ' . (string) $row['note'] ), 0, 255 ),
				'decisions'  => wp_json_encode( $dec, JSON_UNESCAPED_SLASHES ),
			],
			[ 'product_id' => $id ]
		);
		do_action( 'mmi_seo_refresh_product_images', $id, $add );
		Module::info( 'Dropped product pictures restored', [ 'product_id' => $id ] + $out );
		return $out;
	}

	/* ── D3: drop the 768/600 copies standard pictures already have ────── */

	/** Remove `medium_large` and `woocommerce_single` from standard pictures; their files are released (moved after the wait). */
	public static function strip_sizes( bool $dry = false ): array {
		global $wpdb;
		$out = [ 'pictures' => 0, 'files' => 0, 'bytes' => 0 ];
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_mmpt_source_size'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		foreach ( $ids as $att ) {
			$meta = wp_get_attachment_metadata( $att, true );
			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
				continue;
			}
			$rel   = (string) get_post_meta( $att, '_wp_attached_file', true );
			$dir   = dirname( $rel ) === '.' ? '' : trailingslashit( dirname( $rel ) );
			$keep  = array_flip( ImageConvert::KEEP_SIZES );
			$drop  = array_diff_key( $meta['sizes'], $keep );
			$kept  = array_column( array_intersect_key( $meta['sizes'], $keep ), 'file' );
			if ( ! $drop ) {
				continue;
			}
			// A dropped size whose file is also a kept size's file (same dimensions) stays.
			// audit-ok: haystack is one attachment's few kept size files
			$files = array_values( array_unique( array_map( static fn( $s ) => $dir . $s['file'], array_filter( $drop, static fn( $s ) => ! in_array( $s['file'], $kept, true ) ) ) ) );
			$out['pictures']++;
			$out['files'] += count( $files );
			$out['bytes'] += MediaRelease::sum_bytes( $files );
			if ( $dry ) {
				continue;
			}
			$meta['sizes'] = array_intersect_key( $meta['sizes'], $keep );
			wp_update_attachment_metadata( $att, $meta );
			if ( $files ) {
				MediaRelease::release( $att, [], 'standard picture: 768/600 copies dropped (D3)', 'sizes', [ 'rel' => $files, 'sizes' => $drop ] );
			}
		}
		if ( ! $dry ) {
			Module::info( 'Standard pictures: 768/600 copies dropped', $out );
		}
		return $out;
	}

	/* ── Reporting ─────────────────────────────────────────────────────── */

	/**
	 * A person's review written now (owner 2026-10-31: "all they see is a product page with incorrect images which they
	 * have managed correctly"): vetoes take pictures off and a featured choice leads the set at once, by the same merge
	 * and record as the runner (Put back undoes it), with nothing downloaded. The page's pictures it would still gain
	 * are queued for the runner. A product the runner is writing right now is left to it.
	 * @return array{result:string,queued:bool} result applied | nothing | busy | skipped | with_new (every picture vetoed: the runner writes it with its page's)
	 */
	public static function apply_now( int $id ): array {
		self::maybe_install();
		global $wpdb;
		$t = self::table();
		self::enqueue( [ $id ], self::REVIEW, true ); // its ledger row, waiting
		// Never left with no picture: when the choices take off every one it has, its page's pictures come in the same
		// write, so the runner does both (2026-10-31: Acoustica 8 products had only the three vetoed headshots).
		$plan = self::plan( $id, true );
		if ( $plan['mode'] === 'vendor' && $plan['current'] && ! PictureMatch::merge( $plan['current'], [], false, $id )['final'] ) {
			return [ 'result' => 'with_new', 'queued' => true ];
		}
		if ( ! $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status = 'working', source = %s WHERE product_id = %d AND status = 'queued'", self::REVIEW, $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL
			return [ 'result' => 'busy', 'queued' => true ];
		}
		try {
			$r = self::process( $id, false, true );
		} catch ( \Throwable $e ) {
			$wpdb->update( $t, [ 'status' => 'queued' ], [ 'product_id' => $id ] ); // the runner tries it
			Module::warn( 'Product pictures: a review could not be written now', [ 'product_id' => $id, 'error' => $e->getMessage() ] );
			return [ 'result' => 'busy', 'queued' => true ];
		}
		// What its page would still add: the runner downloads those (the same review, on top of this write).
		$plan  = self::plan( $id, true );
		$more  = $plan['mode'] === 'vendor' && $plan['urls'] && PictureMatch::merge( $plan['current'], $plan['urls'], false, $id )['changed'];
		if ( $more ) {
			self::enqueue( [ $id ], self::REVIEW, true );
		}
		$res = $r['result'] === 'applied' ? 'applied' : ( ( $r['reason'] ?? '' ) === 'nothing_to_add' ? 'nothing' : (string) $r['result'] );
		return [ 'result' => $res, 'queued' => (bool) $more ];
	}

	/**
	 * The product page as cached for visitors goes (2026-10-31): RunCloud's nginx copy through the loopback purge path
	 * (RunCache never clears it: memory, 2026-10-30), then RunCache's own purge hooks (Cloudflare and the rest).
	 */
	// audit-ok: one loopback GET to this server's own nginx purge path (127.0.0.1); no rate limit
	public static function purge_page( int $id ): void {
		$url = (string) get_permalink( $id );
		if ( $url === '' ) {
			return;
		}
		$p = wp_parse_url( $url );
		if ( ! empty( $p['host'] ) ) {
			wp_remote_request( 'https://127.0.0.1/runcache-purge-proxy' . ( $p['path'] ?? '/' ), [ 'method' => 'GET', 'headers' => [ 'Host' => $p['host'] ], 'sslverify' => false, 'timeout' => 3, 'redirection' => 0 ] ); // 200 purged, 412 not cached
		}
		do_action( 'runcache_after_purge_post', $id, [ $url ] );
	}

	/**
	 * Where these products' pictures stand in the runner's queue (owner 2026-10-31: "Waiting for the picture runner"
	 * says nothing of when): each one's place and an estimate, and the runner's state — working, paused (why, from its
	 * last beat), at the day’s limit (until when), starting (the watchdog just started it), restarting (a hung one)
	 * or stopped (nothing it may start).
	 * @param int[] $ids
	 * @return array{runner:array,items:array<int,array>}
	 */
	public static function queue_status( array $ids ): array {
		self::maybe_install();
		global $wpdb;
		$t     = self::table();
		$ids   = array_slice( array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) ), 0, 600 );
		$watch = self::ensure(); // a screen that asks never waits on a restart: started here when a pass waits
		$state = Module::state( self::STATE );
		$cap   = self::limits()['cap'];
		$done  = self::done_today();
		$alive = self::runner_alive();
		$beat  = strtotime( (string) ( $state['beat_at'] ?? '' ) . ' UTC' ) ?: 0;
		$note  = (string) ( $state['note'] ?? '' );
		$fresh = $alive && time() - $beat < self::PAUSE_S + 90;
		$queue = array_map( 'intval', $wpdb->get_col( "SELECT product_id FROM {$t} WHERE status = 'queued' ORDER BY " . self::QUEUE_ORDER . ' LIMIT 5000' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$place = array_flip( $queue );
		$work  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('working','revert')" ); // phpcs:ignore WordPress.DB.PreparedSQL
		// Pace: the median gap between the runner's recent products, within a run (gaps over 15 minutes are pauses).
		$at   = array_map( 'strtotime', $wpdb->get_col( $wpdb->prepare( "SELECT CONCAT( run_at, ' UTC' ) FROM {$t} WHERE status IN ('applied','skipped','error') AND run_at IS NOT NULL ORDER BY run_at DESC LIMIT %d", self::PACE_ROWS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$gaps = [];
		for ( $i = 1, $n = count( $at ); $i < $n; $i++ ) {
			$g = $at[ $i - 1 ] - $at[ $i ];
			if ( $g > 0 && $g < 15 * MINUTE_IN_SECONDS ) {
				$gaps[] = $g;
			}
		}
		sort( $gaps );
		$pace = $gaps ? (int) $gaps[ intdiv( count( $gaps ), 2 ) ] : 0;
		$slow = $fresh && str_starts_with( $note, 'Slowed:' );
		if ( $slow ) {
			$pace = max( $pace, self::SLOW_S );
		}
		// At the limit: the products that drop out of the last 24 hours make room, oldest first.
		$resume = null;
		if ( $done >= $cap ) {
			$k      = $done - $cap;
			$resume = (string) $wpdb->get_var( $wpdb->prepare( "SELECT DATE_ADD( run_at, INTERVAL 1 DAY ) FROM {$t} WHERE status IN ('applied','reverted') AND run_at > UTC_TIMESTAMP() - INTERVAL 1 DAY ORDER BY run_at LIMIT %d, 1", $k ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		$mode = match ( true ) {
			in_array( $watch, [ 'started', 'starting' ], true ) => 'starting',
			$watch === 'hung'                                  => 'restarting', // the watchdog (WP-Cron) stops it within a minute
			! $alive                                           => $done >= $cap ? 'limit' : 'stopped', // nothing it may start: no open pass
			! $fresh                                           => 'working', // mid-product
			str_starts_with( $note, 'Paused:' )                => 'paused',
			str_starts_with( $note, 'Daily limit' )            => 'limit',
			$slow                                              => 'slowed',
			default                                            => 'working',
		};
		$pass = (array) ( $state['pass'] ?? [] );
		$pdone = ! empty( $pass['started_at'] ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status IN ('applied','skipped','error') AND run_at >= %s", (string) $pass['started_at'] ) ) : 0; // phpcs:ignore WordPress.DB.PreparedSQL
		$items = [];
		if ( $ids ) {
			foreach ( $wpdb->get_results( "SELECT product_id, status, reason, run_at FROM {$t} WHERE product_id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
				$id    = (int) $r['product_id'];
				$ahead = isset( $place[ $id ] ) ? $place[ $id ] + $work : null;
				$items[ $id ] = [
					'status' => (string) $r['status'], // queued | working | revert | applied | skipped | error | reverted
					'ahead'  => $ahead,
					'eta'    => $ahead !== null && $pace && in_array( $mode, [ 'working', 'slowed', 'starting', 'restarting' ], true ) ? ( $ahead + 1 ) * $pace : null, // seconds
					'reason' => (string) $r['reason'],
				];
			}
		}
		return [
			'runner' => [
				'mode'       => $mode,
				'note'       => preg_replace( '/^(Paused|Slowed): /', '', $note ),
				'done_today' => $done,
				'cap'        => $cap,
				'resume_at'  => $resume ?: null, // UTC
				'pace'       => $pace ?: null,
				'waiting'    => count( $queue ) + $work,
				'pass_done'  => $pdone,
			],
			'items'  => (object) $items,
		];
	}

	/** Counts for the summary table, the runner's state and what may run now. */
	public static function summary(): array {
		self::maybe_install();
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT status, mode, reason, COUNT(*) n, SUM(pics_before) pb, SUM(pics_after) pa, SUM(bytes_before) bb, SUM(bytes_after) ba FROM {$t} GROUP BY status, mode, reason", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = [];
		foreach ( $rows as $r ) {
			$out[] = [
				'status'       => $r['status'],
				'mode'         => $r['mode'],
				'reason'       => $r['reason'],
				'reason_label' => self::REASONS[ $r['reason'] ] ?? $r['reason'],
				'n'            => (int) $r['n'],
				'pics_before'  => (int) $r['pb'],
				'pics_after'   => (int) $r['pa'],
				'bytes_before' => (int) $r['bb'],
				'bytes_after'  => (int) $r['ba'],
			];
		}
		$act = Activity::check();
		return [
			'groups'     => $out,
			'release'    => MediaRelease::totals(),
			'folders'    => MediaRelease::folders(),
			'running'    => self::runner_alive(),
			'done_today' => self::done_today(),
			'cap'        => self::limits()['cap'],
			'waiting'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('queued','revert')" ), // phpcs:ignore WordPress.DB.PreparedSQL
			'activity'   => $act,
			'pass'       => (array) ( Module::state( self::STATE )['pass'] ?? [] ),
			'state'      => array_intersect_key( Module::state( self::STATE ), array_flip( [ 'started_at', 'finished_at', 'beat_at', 'note', 'kicked_at', 'last_run' ] ) ),
		];
	}

	/** Ledger rows for the table, newest first. */
	public static function rows( array $f ): array {
		self::maybe_install();
		global $wpdb;
		$t     = self::table();
		$where = [ "l.status NOT IN ('queued','working')" ];
		$args  = [];
		$map   = [ 'vendor' => "l.mode = 'vendor' AND l.status = 'applied'", 'in_place' => "l.mode = 'in_place' AND l.status = 'applied'", 'skipped' => "l.status = 'skipped'", 'error' => "l.status = 'error'", 'reverted' => "l.status IN ('reverted','revert')", 'queued' => "l.status IN ('queued','working')" ];
		if ( isset( $map[ $f['show'] ?? '' ] ) ) {
			$where = [ $map[ $f['show'] ] ];
		}
		if ( ! empty( $f['reason'] ) ) {
			$where[] = 'l.reason = %s';
			$args[]  = (string) $f['reason'];
		}
		$per    = 50;
		$page   = max( 1, (int) ( $f['page'] ?? 1 ) );
		$sorts  = [ 'id' => [ 'l.product_id' ], 'title' => [ 'p.post_title' ], 'outcome' => [ 'l.status', 'l.mode' ], 'reason' => [ 'l.reason' ], 'pics' => [ 'l.pics_before' ], 'before' => [ 'l.bytes_before' ], 'after' => [ 'l.bytes_after' ], 'run_at' => [ 'COALESCE(l.run_at, l.queued_at)' ] ];
		$dir    = strtolower( (string) ( $f['order'] ?? '' ) ) === 'asc' ? 'ASC' : 'DESC';
		$order  = implode( ', ', array_map( static fn( $c ) => "{$c} {$dir}", $sorts[ $f['orderby'] ?? '' ] ?? $sorts['run_at'] ) );
		$sql    = "FROM {$t} l LEFT JOIN {$wpdb->posts} p ON p.ID = l.product_id WHERE " . implode( ' AND ', $where );
		$total  = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$sql}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) {$sql}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$select = "SELECT l.product_id, p.post_title title, l.status, l.mode, l.reason, l.source_url, l.pics_before, l.pics_after, l.bytes_before, l.bytes_after, l.note, l.run_at {$sql} ORDER BY {$order}, l.product_id DESC LIMIT %d OFFSET %d";
		$list   = $wpdb->get_results( $wpdb->prepare( $select, array_merge( $args, [ $per, ( $page - 1 ) * $per ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $list as &$r ) {
			$r['reason_label'] = self::REASONS[ $r['reason'] ] ?? $r['reason'];
			$r['title']        = html_entity_decode( (string) $r['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$r['thumb']        = (string) wp_get_attachment_image_url( (int) get_post_thumbnail_id( (int) $r['product_id'] ), 'thumbnail' );
			$r['edit']         = (string) get_edit_post_link( (int) $r['product_id'], 'raw' );
		}
		return [ 'rows' => $list, 'total' => $total, 'page' => $page, 'per_page' => $per ];
	}

	/**
	 * Every product with pictures: what plan() decides and what its files weigh
	 * (CLI; reads stored pages only). Writes a CSV to the private log folder.
	 */
	public static function census( array $only = [] ): array {
		global $wpdb;
		$ids = $only ?: array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value > 0 WHERE p.post_type = 'product' AND p.post_status = 'publish' ORDER BY p.ID" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		$dir = mmi_shared_lib_private_subdir( 'mmpt-logs' );
		$csv = $dir !== '' ? $dir . 'pictures-census-' . gmdate( 'Ymd-His' ) . '.csv' : '';
		$fh  = $csv !== '' ? fopen( $csv, 'w' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh ) {
			fputcsv( $fh, [ 'product_id', 'mode', 'reason', 'pictures', 'standard', 'bytes', 'vendor_pictures' ] );
		}
		$sum = [ 'products' => 0, 'pictures' => 0, 'bytes' => 0, 'by' => [] ];
		foreach ( $ids as $id ) {
			$p     = self::plan( $id );
			$bytes = self::bytes_of( $p['current'] );
			$std   = count( array_filter( $p['current'], [ ImageConvert::class, 'is_standard' ] ) );
			$key   = $p['mode'] . ':' . $p['reason'];
			$sum['products']++;
			$sum['pictures'] += count( $p['current'] );
			$sum['bytes']    += $bytes;
			$sum['by'][ $key ]['n']     = ( $sum['by'][ $key ]['n'] ?? 0 ) + 1;
			$sum['by'][ $key ]['bytes'] = ( $sum['by'][ $key ]['bytes'] ?? 0 ) + $bytes;
			if ( $fh ) {
				fputcsv( $fh, [ $id, $p['mode'], $p['reason'], count( $p['current'] ), $std, $bytes, count( $p['urls'] ) ] );
			}
		}
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		ksort( $sum['by'] );
		return $sum + [ 'csv' => $csv ];
	}

	/* ── REST (Vendor pages tab → Product pictures) ────────────────────── */

	public static function routes(): void {
		$admin = static fn() => current_user_can( 'manage_woocommerce' );
		register_rest_route( Module::NS, '/pictures', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( self::summary() ) ] );
		register_rest_route( Module::NS, '/pictures/rows', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( self::rows( [ 'show' => (string) $r->get_param( 'show' ), 'reason' => (string) $r->get_param( 'reason' ), 'page' => (int) $r->get_param( 'page' ), 'orderby' => (string) $r->get_param( 'orderby' ), 'order' => (string) $r->get_param( 'order' ) ] ) ) ] );
		register_rest_route(
			Module::NS,
			'/pictures/(?P<id>\d+)/revert',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					$id  = (int) $r['id'];
					$res = self::revert( $id );
					if ( class_exists( 'MMI_Audit_Log' ) ) {
						\MMI_Audit_Log::record( 'mmi-admin', 'product_images.revert', [ 'object_type' => 'product', 'object_id' => $id, 'outcome' => $res['result'] === 'error' ? 'failure' : 'success', 'details' => $res ] );
					}
					return new \WP_REST_Response( $res, $res['result'] === 'error' ? 400 : 200 );
				},
			]
		);
		// Where products' pictures stand in the runner's queue (the screens poll it while any wait).
		register_rest_route(
			Module::NS,
			'/pictures/queue',
			[
				'methods'             => 'GET',
				'permission_callback' => $admin,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( self::queue_status( array_map( 'absint', explode( ',', (string) $r->get_param( 'ids' ) ) ) ) ),
			]
		);
		// A person's review written (Review pictures): these products' pictures as their choices leave them, by the runner.
		register_rest_route(
			Module::NS,
			'/pictures/write',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'ids' => [ 'required' => true ] ],
				'callback'            => static function ( \WP_REST_Request $r ) {
					$ids = array_values( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ), static fn( $id ) => get_post_type( $id ) === 'product' ) );
					if ( ! $ids ) {
						return new \WP_REST_Response( [ 'message' => 'No products to write.' ], 400 );
					}
					// The choices now, product by product, within the request's time; the rest (and the page's new pictures) to the runner.
					$ids   = array_slice( $ids, 0, 600 );
					$start = microtime( true );
					$out   = [];
					foreach ( $ids as $id ) {
						$out[ $id ] = microtime( true ) - $start < 20 ? self::apply_now( $id ) : [ 'result' => 'later', 'queued' => (bool) self::enqueue( [ $id ], self::REVIEW, true ) ];
					}
					self::kick();
					if ( class_exists( 'MMI_Audit_Log' ) ) {
						\MMI_Audit_Log::record( 'mmi-admin', 'product_images.review_write', [ 'object_type' => 'product', 'object_id' => (string) $ids[0], 'details' => [ 'products' => $ids, 'results' => array_count_values( array_column( $out, 'result' ) ) ] ] );
					}
					return rest_ensure_response( [ 'results' => (object) $out ] + self::queue_status( $ids ) );
				},
			]
		);
		register_rest_route(
			Module::NS,
			'/pictures/stop',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function () {
					$n = self::clear_pending();
					if ( class_exists( 'MMI_Audit_Log' ) ) {
						\MMI_Audit_Log::record( 'mmi-admin', 'product_images.stop', [ 'object_type' => 'queue', 'details' => [ 'removed' => $n ] ] );
					}
					return rest_ensure_response( [ 'removed' => $n, 'summary' => self::summary() ] );
				},
			]
		);
	}
}
