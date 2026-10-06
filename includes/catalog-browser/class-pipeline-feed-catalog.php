<?php
/**
 * MMI_Pipeline_Feed_Catalog
 *
 * A supplier's catalog as of the last feed fetch, browsable from that
 * supplier plugin's own admin page (XChange, SkuPort, Plugivery). Reads the
 * feed files this plugin's fetches write — never the supplier's API — so
 * browsing costs no API requests.
 *
 * One subclass per supplier (the "adapter", living in that supplier's
 * plugin) says which files make up its feed and how a record becomes a table
 * row and a detail view. Everything else is here, once:
 *   - index.json (one compact row per SKU) plus per-SKU detail shards in a
 *     private folder, rebuilt only when a feed file's mtime/size changes;
 *   - server-side search, filters, sort and paging;
 *   - each file's age, the running fetch, the cooldown and next scheduled
 *     fetch; "Fetch now" through MMI_Pipeline_Cron::queue_manual_fetch(),
 *     the schedule's own rate-limited path under its own lock;
 *   - one set of AJAX actions (mmi_feed_catalog_*), dispatched by `source`.
 * The page itself: feed-catalog-tab.php + assets/js/feed-catalog.js.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

abstract class MMI_Pipeline_Feed_Catalog {

    const NONCE    = 'mmi_feed_catalog';
    const SHARDS   = 32;
    /** The table grows to fit the page size (no inner scroll), so 500 is one long page. */
    const PER_PAGE = [ 25, 50, 100, 250, 500 ];

    /** @var array<string,MMI_Pipeline_Feed_Catalog> */
    private static $registry = [];

    /** @var array|null */
    private $index = null;

    /* ── What a supplier adapter provides ────────────────────────────────── */

    /** Source ID, matching the pipeline's supplier ID ('xchange'). */
    abstract public function id(): string;

    /** Display name ('XChange'). */
    abstract public function label(): string;

    /**
     * Feed files shown in the freshness strip, key => [ 'file' => basename
     * in the feed dir, 'label' => text, 'index' => bool (part of the index
     * signature), 'interval' => seconds between fetches if not the source's
     * own schedule ].
     */
    abstract protected function files(): array;

    /**
     * Parses the feed into table rows. Detail data goes to shards through
     * $write_part( 'name', [ sku => data ] ) — call it once per big file so
     * no two have to be in memory together.
     *
     * Each row needs `sku`, `product` and may carry `sub` (second line),
     * `image`, `promo` (current_promo()) and any keys its columns()/facets()
     * name. `cost` and `map` are the regular prices (regular_prices()).
     *
     * @return array{rows:array, counts?:array<string,int>}
     */
    abstract protected function build_rows( callable $write_part ): array;

    /**
     * One SKU's detail from its shard parts: { images: [], images_note?: string,
     * text: [label => html], lists: [label => string|array], promos: [ raw
     * feed promotion records, shaped by promotion() ], fields: [key => value] }.
     */
    abstract protected function detail_from_parts( string $sku, array $parts ): array;

    /** Table columns: [ key, label, type (sku|product|text|money|date|promo|site), title? ]. */
    abstract protected function columns(): array;

    /** Filter dropdowns built from row values: key => "All …" label. */
    abstract protected function facets(): array;

    /** Whether the current user may use this page (the plugin's own filterable capability). */
    abstract public function user_can(): bool;

    /** Row keys searched by the search box. */
    protected function search_keys(): array {
        return [ 'sku', 'product' ];
    }

    /** Postmeta keys holding this supplier's SKU on WooCommerce products. */
    protected function sku_meta_keys(): array {
        return [ '_mmi_supplier_sku_' . $this->id() ];
    }

    /** The file whose age gates "Fetch now", and for how long. */
    protected function cooldown_file(): string {
        return (string) array_key_first( $this->files() );
    }

    protected function cooldown_seconds(): int {
        return 600;
    }

    /** A reason Fetch now must not run right now (e.g. an API quota), or ''. */
    protected function fetch_block_reason(): string {
        return '';
    }

    /** Called after a manual fetch was queued (e.g. a second job to start alongside). */
    protected function after_fetch_started(): void {}

    /** Extra lines for the freshness strip: [ { text, busy } ]. */
    protected function extra_status(): array {
        return [];
    }

    /** Whether rows without a feed image use our listing's featured image. */
    protected function site_thumbs(): bool {
        return true;
    }

    /** One sentence under the section title. */
    protected function description(): string {
        return sprintf(
            /* translators: %s: supplier name */
            __( 'Every product in %s\'s feed as of the last fetch. Browsing reads the saved feed files, so it uses no API requests; "Fetch now" queues the same rate-limited fetch the schedule runs.', 'mmi-data-pipeline' ),
            $this->label()
        );
    }

    protected function audit( string $action, array $args ): void {
        if ( class_exists( 'MMI_Audit_Log' ) ) {
            MMI_Audit_Log::record( 'mmi-data-pipeline', $action, $args );
        }
    }

    /* ── Registry + AJAX ─────────────────────────────────────────────────── */

    public static function register( MMI_Pipeline_Feed_Catalog $catalog ): void {
        self::$registry[ $catalog->id() ] = $catalog;
        static $hooked = false;
        if ( ! $hooked ) {
            $hooked = true;
            foreach ( [ 'query', 'detail', 'status', 'fetch_now' ] as $action ) {
                add_action( 'wp_ajax_mmi_feed_catalog_' . $action, [ __CLASS__, 'ajax_' . $action ] );
            }
            add_action( 'admin_enqueue_scripts', [ __CLASS__, 'register_assets' ], 5 );
        }
    }

    public static function get( string $id ): ?MMI_Pipeline_Feed_Catalog {
        return self::$registry[ $id ] ?? null;
    }

    public static function register_assets(): void {
        wp_register_style( 'mmi-feed-catalog', MMI_PIPELINE_URL . 'assets/css/feed-catalog.css', [ 'mmi-suite-common' ], MMI_PIPELINE_VERSION );
        wp_register_script( 'mmi-feed-catalog', MMI_PIPELINE_URL . 'assets/js/feed-catalog.js', [ 'jquery', 'mmi-pagination' ], MMI_PIPELINE_VERSION, true );
    }

    /** The supplier page's tab calls this from its enqueue hook. */
    public function enqueue(): void {
        self::register_assets();
        wp_enqueue_style( 'mmi-feed-catalog' );
        wp_enqueue_script( 'mmi-feed-catalog' );
    }

    private static function from_request(): MMI_Pipeline_Feed_Catalog {
        check_ajax_referer( self::NONCE, 'nonce' );
        $catalog = self::get( sanitize_key( wp_unslash( $_POST['source'] ?? '' ) ) );
        if ( ! $catalog ) {
            wp_send_json_error( [ 'message' => __( 'Unknown feed.', 'mmi-data-pipeline' ) ] );
        }
        if ( ! $catalog->user_can() ) {
            $catalog->audit( 'ajax.denied', [ 'outcome' => 'denied', 'details' => [ 'ajax_action' => sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ), 'source' => $catalog->id() ] ] );
            wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'mmi-data-pipeline' ) ] );
        }
        return $catalog;
    }

    public static function ajax_query(): void {
        $catalog = self::from_request();
        $in      = wp_unslash( $_POST );
        // Every filter is a multi-select: a list of values, any of which matches.
        $list    = static fn( $v ) => array_values( array_filter( array_map( 'sanitize_text_field', array_map( 'strval', (array) ( $v ?? [] ) ) ), 'strlen' ) );
        $filters = [];
        foreach ( array_keys( $catalog->facets() ) as $key ) {
            $filters[ $key ] = $list( $in['f'][ $key ] ?? [] );
        }
        try {
            wp_send_json_success( $catalog->query( [
                'q'        => sanitize_text_field( $in['q'] ?? '' ),
                'filters'  => $filters,
                'promo'    => array_intersect( $list( $in['promo'] ?? [] ), [ 'active', 'upcoming', 'none' ] ),
                'on_site'  => array_intersect( $list( $in['on_site'] ?? [] ), [ 'publish', 'other', 'none' ] ),
                'sort'     => sanitize_key( $in['sort'] ?? 'product' ),
                'dir'      => ( $in['dir'] ?? '' ) === 'desc' ? 'desc' : 'asc',
                'page'     => max( 1, absint( $in['page'] ?? 1 ) ),
                'per_page' => absint( $in['per_page'] ?? 50 ),
            ] ) );
        } catch ( Throwable $e ) {
            wp_send_json_error( [ 'message' => $e->getMessage() ] );
        }
    }

    public static function ajax_detail(): void {
        $catalog = self::from_request();
        $sku     = sanitize_text_field( wp_unslash( $_POST['sku'] ?? '' ) );
        $detail  = $sku !== '' ? $catalog->detail( $sku ) : null;
        if ( ! $detail ) {
            wp_send_json_error( [ 'message' => __( 'That SKU isn\'t in the current feed.', 'mmi-data-pipeline' ) ] );
        }
        wp_send_json_success( $detail );
    }

    public static function ajax_status(): void {
        wp_send_json_success( self::from_request()->status() );
    }

    public static function ajax_fetch_now(): void {
        $catalog = self::from_request();
        $result  = $catalog->fetch_now();
        $catalog->audit( 'source.fetch', [
            'object_type' => 'data_source',
            'object_id'   => $catalog->id(),
            'outcome'     => $result['started'] ? 'success' : 'failure',
            'details'     => [ 'trigger' => 'feed_catalog', 'reason' => $result['message'] ?? '' ],
        ] );
        $result['status'] = $catalog->status();
        $result['started'] ? wp_send_json_success( $result ) : wp_send_json_error( $result );
    }

    /* ── Fetch ───────────────────────────────────────────────────────────── */

    public function cooldown_remaining(): int {
        $path = $this->path( $this->cooldown_file() );
        return file_exists( $path ) ? max( 0, $this->cooldown_seconds() - ( time() - filemtime( $path ) ) ) : 0;
    }

    /** @return array{started:bool, message?:string} */
    public function fetch_now(): array {
        if ( ! method_exists( 'MMI_Pipeline_Cron', 'queue_manual_fetch' ) ) {
            return [ 'started' => false, 'message' => __( 'Fetching isn\'t available on this site.', 'mmi-data-pipeline' ) ];
        }
        if ( MMI_Pipeline_Cron::is_fetch_running( $this->id() ) ) {
            return [ 'started' => false, 'message' => __( 'A fetch is already running.', 'mmi-data-pipeline' ) ];
        }
        $cooldown = $this->cooldown_remaining();
        if ( $cooldown > 0 ) {
            return [ 'started' => false, 'message' => sprintf(
                /* translators: %s: time until the next fetch is allowed */
                __( 'Fetched moments ago; the next fetch is allowed in %s.', 'mmi-data-pipeline' ),
                human_time_diff( time(), time() + $cooldown )
            ) ];
        }
        $blocked = $this->fetch_block_reason();
        if ( $blocked !== '' ) {
            return [ 'started' => false, 'message' => $blocked ];
        }
        $queued = MMI_Pipeline_Cron::queue_manual_fetch( $this->id() );
        if ( empty( $queued['started'] ) ) {
            return [ 'started' => false, 'message' => __( 'A fetch is already running.', 'mmi-data-pipeline' ) ];
        }
        $this->after_fetch_started();
        return [ 'started' => true ];
    }

    /* ── Files + status ──────────────────────────────────────────────────── */

    protected function path( string $key ): string {
        return trailingslashit( mmi_shared_lib_json_dir() ) . $this->files()[ $key ]['file'];
    }

    protected function read_feed( string $key ): array {
        $path = $this->path( $key );
        $data = file_exists( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null;
        return is_array( $data ) ? $data : [];
    }

    private function cache_dir(): string {
        return mmi_shared_lib_private_subdir( 'feed-catalog-' . $this->id() );
    }

    private function read_cache( string $name ): array {
        $file = $this->cache_dir() . $name;
        $data = file_exists( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
        return is_array( $data ) ? $data : [];
    }

    /** Seconds between this source's scheduled fetches (hourly if unknown). */
    protected function schedule_interval(): int {
        $schedule  = wp_get_schedule( 'mmi_pipeline_source_fetch_' . $this->id() );
        $schedules = wp_get_schedules();
        return (int) ( ( $schedule && isset( $schedules[ $schedule ]['interval'] ) ) ? $schedules[ $schedule ]['interval'] : HOUR_IN_SECONDS );
    }

    public function status(): array {
        $index    = $this->read_cache( 'index.json' );
        $interval = $this->schedule_interval();
        $files    = [];
        foreach ( $this->files() as $key => $def ) {
            $path  = $this->path( $key );
            $mtime = file_exists( $path ) ? filemtime( $path ) : null;
            $age   = $mtime ? time() - $mtime : null;
            // Judged against the file's own cadence: fresh within 1.5 ×
            // its interval (the suite's stale-process threshold), aging
            // within 3 ×, old beyond.
            $every = (int) ( $def['interval'] ?? $interval );
            $files[] = [
                'key'   => $key,
                'label' => $def['label'],
                'at'    => $mtime ? wp_date( 'M j, g:i a', $mtime ) : null,
                'age'   => $age,
                'band'  => $age === null ? 'missing' : ( $age < 1.5 * $every ? 'fresh' : ( $age < 3 * $every ? 'aging' : 'old' ) ),
                'count' => $index['counts'][ $key ] ?? null,
            ];
        }

        $pipeline = method_exists( 'MMI_Pipeline_Cron', 'queue_manual_fetch' );
        $running  = $pipeline && MMI_Pipeline_Cron::is_fetch_running( $this->id() );
        $run      = $running ? MMI_Pipeline_Cron::fetch_run_state( $this->id() ) : null;
        $extra    = $this->extra_status();
        $next     = wp_next_scheduled( 'mmi_pipeline_source_fetch_' . $this->id() );

        return [
            'files'     => $files,
            'can_fetch' => $pipeline,
            'feed_run'  => $running ? [
                'step'  => isset( $run['steps'], $run['step_index'] ) ? (string) ( $run['steps'][ $run['step_index'] ] ?? '' ) : '',
                'index' => (int) ( $run['step_index'] ?? 0 ),
                'steps' => $run['steps'] ?? [],
            ] : null,
            'extra'     => $extra,
            'fetching'  => $running || (bool) array_filter( $extra, static fn( $e ) => ! empty( $e['busy'] ) ),
            'cooldown'  => $this->cooldown_remaining(),
            'blocked'   => $this->fetch_block_reason(),
            'next_at'   => $next ? wp_date( 'M j, g:i a', $next ) : null,
        ];
    }

    /* ── Index ───────────────────────────────────────────────────────────── */

    private function signature(): string {
        // The adapter's own file too, so a change to how it builds rows
        // rebuilds the index without waiting for the next fetch.
        $adapter = ( new ReflectionClass( $this ) )->getFileName();
        $parts   = [ static::class, (string) @filemtime( $adapter ), (string) filemtime( __FILE__ ) ];
        foreach ( $this->files() as $key => $def ) {
            if ( ! empty( $def['index'] ) ) {
                $path    = $this->path( $key );
                $parts[] = file_exists( $path ) ? filemtime( $path ) . ':' . filesize( $path ) : '-';
            }
        }
        return md5( implode( '|', $parts ) );
    }

    public function index(): array {
        if ( $this->index !== null ) {
            return $this->index;
        }
        $index = $this->read_cache( 'index.json' );
        if ( ( $index['signature'] ?? '' ) !== $this->signature() ) {
            $index = $this->build();
        }
        return $this->index = $index;
    }

    /** Locked, so two requests right after a fetch don't both parse the feed. */
    private function build(): array {
        $dir  = $this->cache_dir();
        $lock = fopen( $dir . 'build.lock', 'c' );
        if ( ! $lock || ! flock( $lock, LOCK_EX ) ) {
            throw new RuntimeException( 'Could not lock the catalog index for rebuilding.' );
        }
        try {
            $index = $this->read_cache( 'index.json' );
            $signature = $this->signature();
            if ( ( $index['signature'] ?? '' ) === $signature ) {
                return $index;
            }
            wp_raise_memory_limit( 'admin' );

            $parts = [];
            $write = function ( string $part, array $by_sku ) use ( $dir, &$parts ) {
                $part   = sanitize_key( $part );
                $shards = array_fill( 0, self::SHARDS, [] );
                foreach ( $by_sku as $sku => $data ) {
                    $shards[ self::shard( (string) $sku ) ][ (string) $sku ] = $data;
                }
                foreach ( $shards as $n => $shard ) {
                    self::write_atomic( $dir . sprintf( '%s-%02d.json', $part, $n ), wp_json_encode( $shard ) );
                }
                $parts[] = $part;
            };
            $built = $this->build_rows( $write );
            $rows  = $built['rows'] ?? [];
            if ( ! $rows ) {
                throw new RuntimeException( sprintf( 'The %s feed file is missing or empty. Run a fetch first.', $this->label() ) );
            }

            $facets = array_fill_keys( array_keys( $this->facets() ), [] );
            foreach ( $rows as $row ) {
                foreach ( $facets as $key => $_ ) {
                    $value = (string) ( $row[ $key ] ?? '' );
                    if ( $value !== '' ) {
                        $facets[ $key ][ $value ] = true;
                    }
                }
            }
            foreach ( $facets as $key => $values ) {
                $values = array_keys( $values );
                natcasesort( $values );
                $facets[ $key ] = array_values( $values );
            }

            $index = [
                'signature' => $signature,
                'built_at'  => current_time( 'mysql' ),
                'parts'     => $parts,
                'counts'    => $built['counts'] ?? [],
                'facets'    => $facets,
                'rows'      => $rows,
            ];
            self::write_atomic( $dir . 'index.json', wp_json_encode( $index ) );
            return $index;
        } finally {
            flock( $lock, LOCK_UN );
            fclose( $lock );
        }
    }

    private static function shard( string $sku ): int {
        return (int) ( crc32( $sku ) % self::SHARDS );
    }

    private static function write_atomic( string $path, $contents ): void {
        $tmp = $path . '.tmp';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_put_contents
        if ( $contents === false || file_put_contents( $tmp, $contents ) === false || ! rename( $tmp, $path ) ) {
            throw new RuntimeException( 'Could not write ' . basename( $path ) );
        }
    }

    /* ── Helpers for adapters ────────────────────────────────────────────── */

    protected static function money( $value ): ?float {
        return MMI_Supplier_Promotion::money( $value );
    }

    /** '', '1900-01-01…' and '0000-…' mean "no date". */
    protected static function feed_date( $value ): string {
        $value = trim( (string) $value );
        return ( $value === '' || strpos( $value, '1900-' ) === 0 || strpos( $value, '0000-' ) === 0 ) ? '' : $value;
    }

    /* ── Promotions: MMI_Supplier_Promotion, one shape for every supplier ── */

    /**
     * One promotion record from this supplier's feed as the shared input
     * shape: { name, code, cost, regular_cost, map, regular_map, start, end }.
     * Prices may be strings; dates may be 'Y-m-d…' strings or Unix
     * timestamps. MMI_Supplier_Promotion (shared library) does the rest —
     * rounding, dates, state, sorting — so a fix there reaches every
     * supplier and every screen that shows promotions.
     */
    protected function promotion( array $raw ): array {
        return $raw;
    }

    /** Raw feed promotions in the shared shape, running first (MMI_Supplier_Promotion::all()). */
    protected function promotions( array $raw, string $product = '' ): array {
        return MMI_Supplier_Promotion::all( $raw, fn( array $r ): array => $this->promotion( $r ), $product );
    }

    /**
     * A table row's `promo`: the promotion that matters today (running
     * now, else the next to start) from raw feed promotions, or null.
     */
    protected function current_promo( array $raw, string $product = '' ): ?array {
        $p = MMI_Supplier_Promotion::current( $this->promotions( $raw, $product ) );
        return $p ? [ 'price' => $p['cost'] ] + $p : null;
    }

    /**
     * A row's regular cost and MAP: the Cost and MAP columns always show the
     * regular price, the Promo column the promotional one.
     *
     * @return array{cost:?float, map:?float}
     */
    protected static function regular_prices( ?array $promo, $cost, $map ): array {
        return MMI_Supplier_Promotion::regular_prices( $promo, $cost, $map );
    }

    /* ── Membership ──────────────────────────────────────────────────────── */

    /** @var array<string,int>|null SKU set from the saved index, per request. */
    private $sku_set = null;

    /**
     * Whether the last fetched feed lists $sku (MMI_Supplier_Rule_Pack's
     * "still sold"), or null when there's no feed to ask. Reads the saved
     * index even when a newer feed hasn't been indexed yet, so a background
     * scan never parses a multi-MB feed; builds it only if none exists.
     */
    public function has_sku( string $sku ): ?bool {
        if ( $this->sku_set === null ) {
            $index = $this->read_cache( 'index.json' );
            if ( empty( $index['rows'] ) ) {
                try {
                    $index = $this->index();
                } catch ( Throwable $e ) {
                    return null;
                }
            }
            $this->sku_set = array_flip( array_map( 'strval', array_column( $index['rows'] ?? [], 'sku' ) ) );
        }
        return $this->sku_set ? isset( $this->sku_set[ $sku ] ) : null;
    }

    /* ── Query ───────────────────────────────────────────────────────────── */

    /**
     * SKU → our WooCommerce listing { id, status, thumb (attachment id) },
     * one query, cached 5 minutes.
     */
    private function site_products(): array {
        $key = 'mmi_feed_catalog_site_' . $this->id();
        $map = get_transient( $key );
        if ( is_array( $map ) ) {
            return $map;
        }
        global $wpdb;
        $meta_keys    = $this->sku_meta_keys();
        $placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value AS sku, p.ID AS id, p.post_status AS status, p.post_parent AS parent, th.meta_value AS thumb
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             LEFT JOIN {$wpdb->postmeta} th ON th.post_id = IF(p.post_parent > 0, p.post_parent, p.ID) AND th.meta_key = '_thumbnail_id'
             WHERE pm.meta_key IN ($placeholders) AND pm.meta_value != ''
               AND p.post_type IN ('product', 'product_variation') AND p.post_status != 'trash'",
            ...$meta_keys
        ), ARRAY_A );
        $map = [];
        foreach ( $rows as $row ) {
            if ( ! isset( $map[ $row['sku'] ] ) || ( $row['status'] === 'publish' && $map[ $row['sku'] ]['status'] !== 'publish' ) ) {
                $map[ $row['sku'] ] = [ 'id' => (int) ( $row['parent'] ?: $row['id'] ), 'status' => (string) $row['status'], 'thumb' => (int) $row['thumb'] ];
            }
        }
        set_transient( $key, $map, 5 * MINUTE_IN_SECONDS );
        return $map;
    }

    public function query( array $args ): array {
        $index = $this->index();
        $site  = $this->site_products();
        $q     = strtolower( trim( $args['q'] ) );
        $keys  = $this->search_keys();

        $rows = [];
        foreach ( $index['rows'] ?? [] as $row ) {
            foreach ( $args['filters'] as $key => $values ) {
                if ( $values && ! in_array( (string) ( $row[ $key ] ?? '' ), $values, true ) ) {
                    continue 2;
                }
            }
            // The index is built once per fetch; a promotion may have
            // started or ended since.
            if ( ! empty( $row['promo'] ) ) {
                $row['promo']['state'] = MMI_Supplier_Promotion::state( $row['promo'] );
                if ( $row['promo']['state'] === 'ended' ) {
                    $row['promo'] = null;
                }
            }
            if ( $args['promo'] && ! in_array( $row['promo']['state'] ?? 'none', $args['promo'], true ) ) {
                continue;
            }
            $listing = $site[ (string) $row['sku'] ] ?? null;
            $listed  = $listing ? ( $listing['status'] === 'publish' ? 'publish' : 'other' ) : 'none';
            if ( $args['on_site'] && ! in_array( $listed, $args['on_site'], true ) ) {
                continue;
            }
            if ( $q !== '' ) {
                $hay = '';
                foreach ( $keys as $key ) {
                    $hay .= ' ' . ( $row[ $key ] ?? '' );
                }
                if ( strpos( strtolower( $hay ), $q ) === false ) {
                    continue;
                }
            }
            $row['promo_price'] = $row['promo']['price'] ?? null;
            $row['on_site']     = $listing ? ( $listing['status'] === 'publish' ? 2 : 1 ) : 0;
            $row['site_id']     = $listing['id'] ?? 0;
            $row['site_status'] = $listing['status'] ?? '';
            $row['site_thumb']  = $listing['thumb'] ?? 0;
            $rows[] = $row;
        }

        $sortable = array_merge( [ 'sku', 'product', 'promo_price', 'on_site' ], array_column( $this->columns(), 0 ) );
        $sort     = in_array( $args['sort'], $sortable, true ) ? $args['sort'] : 'product';
        $sort     = $sort === 'promo' ? 'promo_price' : ( $sort === 'site' ? 'on_site' : $sort );
        $dir      = $args['dir'] === 'desc' ? -1 : 1;
        usort( $rows, static function ( $a, $b ) use ( $sort, $dir ) {
            $av = $a[ $sort ] ?? null;
            $bv = $b[ $sort ] ?? null;
            if ( $av === null || $av === '' ) {
                return ( $bv === null || $bv === '' ) ? 0 : 1;
            }
            if ( $bv === null || $bv === '' ) {
                return -1;
            }
            $cmp = ( is_numeric( $av ) && is_numeric( $bv ) ) ? ( $av <=> $bv ) : strnatcasecmp( (string) $av, (string) $bv );
            return $cmp * $dir;
        } );

        $per_page = in_array( $args['per_page'], self::PER_PAGE, true ) ? $args['per_page'] : 50;
        $total    = count( $rows );
        $pages    = max( 1, (int) ceil( $total / $per_page ) );
        $page     = min( $args['page'], $pages );
        $slice    = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

        // Thumbnails only for the rows on screen, their attachments primed
        // in one query rather than one lookup per row.
        if ( $this->site_thumbs() ) {
            $thumb_ids = array_filter( array_map( static fn( $r ) => empty( $r['image'] ) ? (int) $r['site_thumb'] : 0, $slice ) );
            if ( $thumb_ids ) {
                _prime_post_caches( array_values( array_unique( $thumb_ids ) ), false, true );
            }
        }
        foreach ( $slice as &$row ) {
            if ( empty( $row['image'] ) && $row['site_thumb'] && $this->site_thumbs() ) {
                $row['image'] = (string) wp_get_attachment_image_url( $row['site_thumb'], 'thumbnail' );
            }
            unset( $row['site_thumb'] );
        }
        unset( $row );

        return [
            'rows'     => $slice,
            'total'    => $total,
            'all'      => count( $index['rows'] ?? [] ),
            'page'     => $page,
            'pages'    => $pages,
            'per_page' => $per_page,
            'facets'   => $index['facets'] ?? [],
            'status'   => $this->status(),
        ];
    }

    /* ── Detail ──────────────────────────────────────────────────────────── */

    public function detail( string $sku ): ?array {
        $index = $this->index();
        $parts = [];
        foreach ( $index['parts'] ?? [] as $part ) {
            $shard = $this->read_cache( sprintf( '%s-%02d.json', $part, self::shard( $sku ) ) );
            if ( isset( $shard[ $sku ] ) ) {
                $parts[ $part ] = $shard[ $sku ];
            }
        }
        if ( ! $parts ) {
            return null;
        }
        $d    = $this->detail_from_parts( $sku, $parts );
        $site = $this->site_products()[ $sku ] ?? null;

        $text = [];
        foreach ( (array) ( $d['text'] ?? [] ) as $label => $html ) {
            if ( is_string( $html ) && trim( wp_strip_all_tags( $html ) ) !== '' ) {
                $text[] = [ 'label' => (string) $label, 'html' => wp_kses_post( wpautop( $html ) ) ];
            }
        }
        $lists = [];
        foreach ( (array) ( $d['lists'] ?? [] ) as $label => $value ) {
            $items = self::flatten_list( $value );
            if ( $items ) {
                $lists[] = [ 'label' => (string) $label, 'items' => $items ];
            }
        }
        $fields = [];
        foreach ( (array) ( $d['fields'] ?? [] ) as $key => $value ) {
            $fields[ (string) $key ] = is_scalar( $value ) || $value === null
                ? (string) $value
                : implode( ', ', array_map( 'strval', array_filter( (array) $value, 'is_scalar' ) ) );
        }
        $images = array_values( array_filter( array_map( 'esc_url_raw', (array) ( $d['images'] ?? [] ) ) ) );
        if ( ! $images && $site && $site['thumb'] && $this->site_thumbs() ) {
            $images = [ (string) wp_get_attachment_image_url( $site['thumb'], 'medium' ) ];
        }

        return [
            'sku'         => $sku,
            'product'     => (string) ( $d['product'] ?? $sku ),
            'images'      => $images,
            'images_note' => (string) ( $d['images_note'] ?? '' ),
            'text'        => $text,
            'lists'       => $lists,
            'promos'      => $this->promotions( (array) ( $d['promos'] ?? [] ), (string) ( $d['product'] ?? '' ) ),
            'fields'      => $fields,
            'site'        => $site ? [
                'status'   => $site['status'],
                'edit_url' => get_edit_post_link( $site['id'], 'raw' ),
                'view_url' => get_permalink( $site['id'] ),
            ] : null,
        ];
    }

    /** A string, list or map as one list of sanitized strings. */
    private static function flatten_list( $value ): array {
        $items = [];
        $walk  = static function ( $v ) use ( &$walk, &$items ) {
            if ( is_array( $v ) ) {
                foreach ( $v as $k => $inner ) {
                    if ( is_scalar( $inner ) && ! is_int( $k ) && trim( (string) $inner ) !== '' ) {
                        $items[] = $k . ': ' . $inner;
                    } else {
                        $walk( $inner );
                    }
                }
            } elseif ( is_scalar( $v ) && trim( wp_strip_all_tags( (string) $v ) ) !== '' ) {
                $items[] = (string) $v;
            }
        };
        $walk( $value );
        return array_slice( array_values( array_unique( array_map( 'wp_kses_post', $items ) ) ), 0, 50 );
    }

    /* ── Page ────────────────────────────────────────────────────────────── */

    /** Prints the tab: the section shell the script fills (feed-catalog-tab.php). */
    public function render(): void {
        $config = [
            'source'   => $this->id(),
            'label'    => $this->label(),
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( self::NONCE ),
            'columns'  => array_map( static fn( $c ) => [ 'key' => $c[0], 'label' => $c[1], 'type' => $c[2], 'title' => $c[3] ?? '' ], $this->columns() ),
            'facets'   => $this->facets(),
            'cooldown' => $this->cooldown_seconds(),
            'perPage'  => self::PER_PAGE,
        ];
        $catalog     = $this;
        $description = $this->description();
        require __DIR__ . '/feed-catalog-tab.php';
    }
}
