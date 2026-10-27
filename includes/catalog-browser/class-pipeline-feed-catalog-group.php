<?php
/**
 * MMI_Pipeline_Feed_Catalog_Group
 *
 * Several suppliers' feed catalogs browsed as one table, in either view
 * (catalog or promotions). Each row is the member's own row, with the
 * member's name (`source`), its brand under one key, and a `key` unique
 * across members ("xchange:ABC-1") that the script sends back for a row's
 * detail. Rows, our listings and promotion checks come from each member,
 * so the group adds no reads of its own.
 *
 * A group has no "Fetch now": each member fetches on its own schedule and
 * its own tab fetches it, so one click never starts several fetches.
 *
 * Registered like any catalog, by the plugin that shows it:
 *   MMI_Pipeline_Feed_Catalog::register( new MMI_Pipeline_Feed_Catalog_Group(
 *       'distributors', 'Distributors', [ 'xchange', 'skuport' ], $user_can ) );
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MMI_Pipeline_Feed_Catalog_Group extends MMI_Pipeline_Feed_Catalog {

    /** @var string */
    private $group_id;

    /** @var string */
    private $group_label;

    /** @var string[] */
    private $member_ids;

    /** @var callable */
    private $can;

    /**
     * @param string[] $member_ids Catalog IDs, resolved when used (members may register later).
     * @param callable $can        Whether the current user may use the group's page.
     */
    public function __construct( string $id, string $label, array $member_ids, callable $can ) {
        $this->group_id    = $id;
        $this->group_label = $label;
        $this->member_ids  = array_values( $member_ids );
        $this->can         = $can;
    }

    public function id(): string {
        return $this->group_id;
    }

    public function label(): string {
        return $this->group_label;
    }

    public function user_can(): bool {
        return (bool) call_user_func( $this->can );
    }

    /** @return array<string,MMI_Pipeline_Feed_Catalog> Registered members the current user may browse. */
    public function members(): array {
        $members = [];
        foreach ( $this->member_ids as $id ) {
            $catalog = self::get( $id );
            if ( $catalog && $id !== $this->group_id && $catalog->user_can() ) {
                $members[ $id ] = $catalog;
            }
        }
        return $members;
    }

    /* ── The group has no feed of its own ────────────────────────────────── */

    protected function files(): array {
        return [];
    }

    protected function build_rows( callable $write_part ): array {
        return [ 'rows' => [] ];
    }

    protected function detail_from_parts( string $sku, array $parts ): array {
        return [];
    }

    protected function fetchable(): bool {
        return false;
    }

    /* ── Table ───────────────────────────────────────────────────────────── */

    protected function columns(): array {
        return [
            [ 'sku', __( 'SKU', 'mmi-data-pipeline' ), 'sku' ],
            [ 'product', __( 'Product', 'mmi-data-pipeline' ), 'product' ],
            [ 'source', __( 'Distributor', 'mmi-data-pipeline' ), 'text' ],
            [ 'brand', __( 'Brand', 'mmi-data-pipeline' ), 'text' ],
            [ 'cost', __( 'Cost', 'mmi-data-pipeline' ), 'money', __( 'Our regular cost from the distributor, before any promotion.', 'mmi-data-pipeline' ) ],
            [ 'map', __( 'MAP', 'mmi-data-pipeline' ), 'money', __( 'Regular MAP, before any promotion.', 'mmi-data-pipeline' ) ],
            [ 'msrp', __( 'MSRP', 'mmi-data-pipeline' ), 'money' ],
            [ 'promo', __( 'Promo', 'mmi-data-pipeline' ), 'promo', __( 'Promotional cost, for a promotion running now or coming up.', 'mmi-data-pipeline' ) ],
            [ 'site', __( 'Our site', 'mmi-data-pipeline' ), 'site' ],
        ];
    }

    protected function facets(): array {
        return [
            'source' => __( 'All distributors', 'mmi-data-pipeline' ),
            'brand'  => __( 'All brands', 'mmi-data-pipeline' ),
        ];
    }

    protected function title( string $view = 'catalog' ): string {
        return $view === 'promotions'
            ? __( 'Every distributor\'s promotions', 'mmi-data-pipeline' )
            : __( 'Every distributor\'s catalog', 'mmi-data-pipeline' );
    }

    protected function description( string $view = 'catalog' ): string {
        return $view === 'promotions'
            ? __( 'Every product with a promotion running now or coming up, at any distributor, next to our own price. Below promo cost means we\'d sell at a loss; above promo MAP means the promotion is being missed. Read-only: pricing imports set our prices.', 'mmi-data-pipeline' )
            : __( 'Every distributor\'s feed in one list, as of each one\'s last fetch. Browsing reads the saved feed files, so it uses no API requests.', 'mmi-data-pipeline' );
    }

    /** Each member's searched keys, plus the distributor's name. */
    protected function haystack_keys(): array {
        $keys = [ 'source', 'brand' ];
        foreach ( $this->members() as $member ) {
            $keys = array_merge( $keys, $member->search_keys() );
        }
        return array_values( array_unique( $keys ) );
    }

    /* ── Rows, facets, detail and status from the members ───────────────── */

    public function rows( string $view = 'catalog' ): array {
        $rows = [];
        foreach ( $this->members() as $id => $member ) {
            $label = $member->label();
            $brand = $member->brand_key();
            foreach ( $member->rows( $view ) as $row ) {
                $row['key']    = $id . ':' . $row['sku'];
                $row['source'] = $label;
                if ( $brand !== 'brand' ) {
                    $row['brand'] = $row[ $brand ] ?? '';
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }

    protected function facet_values(): array {
        $sources = [];
        $brands  = [];
        foreach ( $this->members() as $member ) {
            $sources[] = $member->label();
            foreach ( (array) ( $member->facet_values()[ $member->brand_key() ] ?? [] ) as $brand ) {
                $brands[ (string) $brand ] = true;
            }
        }
        $brands = array_keys( $brands );
        natcasesort( $brands );
        return [ 'source' => $sources, 'brand' => array_values( $brands ) ];
    }

    /** $key is a row's `key`: "{member id}:{sku}". */
    public function detail( string $key ): ?array {
        $at     = strpos( $key, ':' );
        $member = $at ? ( $this->members()[ substr( $key, 0, $at ) ] ?? null ) : null;
        $detail = $member ? $member->detail( substr( $key, $at + 1 ) ) : null;
        if ( $detail ) {
            $detail['fields'] = [ __( 'Distributor', 'mmi-data-pipeline' ) => $member->label() ] + $detail['fields'];
        }
        return $detail;
    }

    /** Every member's files and running fetch in one freshness strip. */
    public function status(): array {
        $files    = [];
        $extra    = [];
        $run      = null;
        $fetching = false;
        $can      = false;
        foreach ( $this->members() as $id => $member ) {
            $status = $member->status();
            foreach ( $status['files'] as $file ) {
                $file['key']   = $id . ':' . $file['key'];
                /* translators: 1: distributor, 2: feed file */
                $file['label'] = sprintf( __( '%1$s · %2$s', 'mmi-data-pipeline' ), $member->label(), $file['label'] );
                $files[]       = $file;
            }
            foreach ( (array) $status['extra'] as $line ) {
                /* translators: 1: distributor, 2: status line */
                $line['text'] = sprintf( __( '%1$s: %2$s', 'mmi-data-pipeline' ), $member->label(), $line['text'] );
                $extra[]      = $line;
            }
            if ( $status['feed_run'] && ! $run ) {
                $run = $status['feed_run'];
                /* translators: 1: distributor, 2: fetch step */
                $run['step'] = sprintf( __( '%1$s %2$s', 'mmi-data-pipeline' ), $member->label(), $run['step'] );
            }
            $fetching = $fetching || $status['fetching'];
            $can      = $can || $status['can_fetch'];
        }
        return [
            'files'     => $files,
            'can_fetch' => $can,
            'feed_run'  => $run,
            'extra'     => $extra,
            'fetching'  => $fetching,
            'cooldown'  => 0,
            'blocked'   => '',
            'next_at'   => null,
            'note'      => __( 'Each distributor fetches on its own schedule. "Fetch now" is on each one\'s own Catalog tab.', 'mmi-data-pipeline' ),
        ];
    }

    public function fetch_now(): array {
        return [ 'started' => false, 'message' => __( 'Fetch each distributor from its own Catalog tab.', 'mmi-data-pipeline' ) ];
    }
}
