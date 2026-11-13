<?php
/**
 * Product Data's badges in the Product Workbench's side menu (filter mmi_pipeline_workbench_counts, answered for
 * WorkbenchController's mmi_workbench_counts):
 *
 *   titles        title proposals to review (proposed, stale, error), as Title proposals' "To review"
 *   descriptions  description proposals waiting, as the section's Descriptions pill
 *   picks         picture proposals waiting, as its Pictures pill
 *   pictures      the picture runner: products queued, products still failed from its last run, running or not
 *   pages         products whose maker page waits, as Maker pages' "Waiting"
 *   decide        products something waits on (Needs a decision, Waiting::total(): its last full count, ≤ 5 minutes old)
 *
 * Every count but the last is one aggregate query. Maker pages' count is PageList's four waiting groups, which take
 * about 2 s to work out, so it is kept in an option: every unscoped page-list request (the Maker pages section
 * itself) stores it as a side effect, and the menu is served the stored count at once. A stored count is rebuilt in
 * the background (Action Scheduler) when it is older than MAX_AGE or something marked it dirty: a maker URL, a kept
 * page or a "no page" mark changed, or any Product Data REST write. The badge is a guide; the section reads live.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WorkbenchCounts {

	const OPTION  = 'mmpt_wb_pages_waiting';
	const HOOK    = 'mmpt_wb_pages_count';
	const MAX_AGE = 900; // seconds a stored maker-pages count is served before it is rebuilt
	/** Meta whose change can move a product into or out of Maker pages' waiting groups. */
	const META = [ 'vendor_product_url', 'vendor_product_url_verified', UrlFinder::PAGE_OK_META ];

	/** Something this request did makes the stored count stale; written once, at shutdown. */
	private static bool $dirty = false;

	public static function register_hooks(): void {
		add_filter( 'mmi_pipeline_workbench_counts', [ __CLASS__, 'counts' ] );
		add_action( self::HOOK, [ __CLASS__, 'rebuild' ] );
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'meta_changed' ], 10, 3 );
		}
		add_filter( 'rest_request_after_callbacks', [ __CLASS__, 'after_rest' ], 10, 3 );
	}

	/** The menu's Product Data badges, added to the Workbench's own. */
	public static function counts( $out ): array {
		global $wpdb;
		$out = (array) $out;
		$x   = Exclusions::sql_not_excluded();
		// audit-ok: $x is Exclusions::sql_not_excluded(), an int id list
		$out['titles'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Store::table() . " WHERE status IN ('proposed','stale','error'){$x}" ); // phpcs:ignore WordPress.DB.PreparedSQL -- table name and a fixed clause
		$kinds = [ 'content' => 0, 'image' => 0 ];
		foreach ( $wpdb->get_results( 'SELECT kind, COUNT(*) n FROM ' . Enrichment::table() . " WHERE status = 'proposed' GROUP BY kind", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$kinds[ $r['kind'] ] = (int) $r['n'];
		}
		$out['descriptions'] = $kinds['content'];
		$out['picks']        = $kinds['image'];

		Pictures::maybe_install();
		$t     = Pictures::table();
		$state = Module::state( Pictures::STATE );
		$since = (string) ( $state['started_at'] ?? '' ); // GMT, as run_at
		$out['pictures'] = [
			'waiting' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status IN ('queued','revert')" ), // phpcs:ignore WordPress.DB.PreparedSQL
			// Still failed from the last run: retried or put right since, they no longer count.
			'errors'  => $since === '' ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE status = 'error' AND run_at >= %s", $since ) ), // phpcs:ignore WordPress.DB.PreparedSQL
			'running' => Pictures::runner_alive(),
		];

		$out['decide'] = Waiting::total();

		$c     = get_option( self::OPTION );
		$c     = is_array( $c ) ? $c : [];
		$stale = ! isset( $c['ids'] ) || ! empty( $c['dirty'] ) || time() - (int) ( $c['at'] ?? 0 ) > self::MAX_AGE;
		$out['pages'] = [
			'n'          => isset( $c['n'] ) ? (int) $c['n'] : null,
			'at'         => (int) ( $c['at'] ?? 0 ),
			'rebuilding' => $stale ? self::rebuild_later() : false,
		];
		return $out;
	}

	/**
	 * PageList worked out the four waiting groups for every product: keep their total for the menu, and their ids
	 * for Needs a decision (Waiting).
	 *
	 * @param int[] $ids
	 */
	public static function store( array $ids ): void {
		self::$dirty = false; // counted after this request's own changes
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		update_option( self::OPTION, [ 'n' => count( $ids ), 'ids' => $ids, 'at' => time(), 'dirty' => false ], false );
	}

	/** The products whose maker page waited at the last count (Waiting's "page" key). @return int[] */
	public static function page_ids(): array {
		$c = get_option( self::OPTION );
		return is_array( $c ) && isset( $c['ids'] ) ? array_map( 'intval', (array) $c['ids'] ) : [];
	}

	/** The background rebuild: an unscoped, counts-only page-list request, which stores the count itself. */
	public static function rebuild(): void {
		$r = new \WP_REST_Request( 'GET', '/' . Rest::NS . '/page-list' );
		$r->set_param( 'rows', '0' );
		PageList::pages( $r );
	}

	/**
	 * Queue a rebuild unless one is already waiting to start (a running one may have read before the latest change,
	 * so only a pending one counts: as_has_scheduled_action() counts running ones too). True while one is queued or
	 * running; false where Action Scheduler isn't there (the badge then waits for the Maker pages section).
	 */
	private static function rebuild_later(): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		$pending = as_get_scheduled_actions( [ 'hook' => self::HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => 1 ], 'ids' );
		if ( ! $pending ) {
			as_enqueue_async_action( self::HOOK, [], Module::GROUP );
		}
		return true;
	}

	public static function meta_changed( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, self::META, true ) ) {
			self::mark();
		}
	}

	/** A Product Data REST write (a URL decided, a page kept, a "no page" mark, a read queued) may move a product. */
	public static function after_rest( $response, $handler, $request ) {
		if ( $request instanceof \WP_REST_Request && $request->get_method() !== 'GET' && str_starts_with( $request->get_route(), '/' . Rest::NS . '/' ) ) {
			self::mark();
		}
		return $response;
	}

	private static function mark(): void {
		if ( self::$dirty ) {
			return;
		}
		self::$dirty = true;
		add_action(
			'shutdown',
			static function () {
				$c = get_option( WorkbenchCounts::OPTION );
				if ( WorkbenchCounts::is_dirty() && is_array( $c ) && empty( $c['dirty'] ) ) {
					$c['dirty'] = true;
					update_option( WorkbenchCounts::OPTION, $c, false );
				}
			}
		);
	}

	/** For the shutdown write: still dirty (a store() later in the request counted after the change). */
	public static function is_dirty(): bool {
		return self::$dirty;
	}
}
