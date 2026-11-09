<?php
/**
 * MMI_Pipeline_Write — one door for every automated or reviewed write to a
 * product field, whichever plugin makes it.
 *
 * Before this, each writer (imports, Custom Rules, the Product Workbench, and
 * a site's own tools) wrote with its own WordPress calls, checked Field Locks
 * its own way or not at all, and kept its own history if any. Now a writer
 * calls field() and gets, in one place:
 *
 *   - the lock check, by mode:
 *       'set'   write unless the field is locked (automated writers);
 *       'fill'  write only when the field is empty and not locked (add-only);
 *       'force' write even if locked: a person decided, or a writer reverts
 *               its own earlier write;
 *   - the write itself (post fields, the featured picture, a taxonomy, or meta);
 *   - optionally a lock afterwards ('lock' => true), so imports can't undo it;
 *   - a row in the Product Workbench change log: Recent changes lists each
 *     writer's work per run (one job per source, renewed daily), and Undo puts
 *     each product's field back unless it was changed again since;
 *   - action 'mmi_pipeline_field_written' ( $id, $field, $new, $old, $source ).
 *
 * Writers say who they are with register_source() so Field Conflicts can name
 * them beside an import profile that maps the same field.
 *
 * Loaded outside the license gate with Field Locks: a lapsed license must not
 * let other writers stop respecting locks, or stop history being kept.
 *
 * Field names: 'post_title', 'post_content', 'post_excerpt', 'post_name',
 * 'post_status' (post fields); '_thumbnail_id' (featured picture, an attachment
 * id, 0 = none); 'tax:{taxonomy}' (term ids); anything else is a meta key
 * (null deletes it).
 *
 * @package MannMade\DataPipeline
 */

use MannMade\DataPipeline\Workbench\Workbench_Change_Log;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class MMI_Pipeline_Write {

    const MODE_SET   = 'set';
    const MODE_FILL  = 'fill';
    const MODE_FORCE = 'force';

    const ACTION      = 'field_write';           // the change-log action of gateway jobs
    const OPEN_JOBS   = 'mmi_pipeline_write_jobs'; // source => [ job_id, day ]
    const POST_FIELDS = [ 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status' ];
    /** Picture fields lock under the importer's image key. */
    const LOCK_KEYS   = [ '_thumbnail_id' => 'images', '_product_image_gallery' => 'images' ];

    /** @var array<string,array{label:string,fields:string[],locks:bool}> */
    private static array $sources = [];
    /** @var array<string,true> job ids written to in this request */
    private static array $touched = [];
    private static bool $flush_hooked = false;

    /* ── Writers ───────────────────────────────────────────────────────── */

    /**
     * Name a writer. $fields: what it writes; $locks: whether it locks what it
     * writes (then an import mapping the same field is no conflict).
     */
    public static function register_source( string $source, string $label, array $fields, bool $locks = false ): void {
        self::$sources[ sanitize_key( $source ) ] = [ 'label' => $label, 'fields' => array_values( array_map( 'strval', $fields ) ), 'locks' => $locks ];
    }

    /** @return array<string,array{label:string,fields:string[],locks:bool}> */
    public static function sources(): array {
        return (array) apply_filters( 'mmi_pipeline_write_sources', self::$sources );
    }

    /* ── Write ─────────────────────────────────────────────────────────── */

    /**
     * Write one field of one product.
     *
     * @param mixed  $value  string/int for post fields and meta (null deletes meta), int for
     *                       '_thumbnail_id' (0 removes it), int[] for 'tax:{taxonomy}'.
     * @param array  $opts   'lock' => bool (lock the field after writing),
     *                       'label' => string (what this write was, for the log).
     * @return array{result:string, lock_added?:bool, message?:string} result: written | unchanged | locked | not_empty | error;
     *         lock_added: this call locked the field (so a writer can unlock only its own lock later).
     */
    public static function field( int $id, string $field, $value, string $source, string $mode = self::MODE_SET, array $opts = [] ): array {
        $source = sanitize_key( $source );
        if ( $id <= 0 || $field === '' || get_post( $id ) === null ) {
            return [ 'result' => 'error', 'message' => 'Unknown product.' ];
        }
        $lock_key = self::lock_key( $field );
        $locked   = class_exists( 'MMI_Pipeline_Field_Locks' ) && MMI_Pipeline_Field_Locks::is_locked( $id, $lock_key );
        if ( $locked && $mode !== self::MODE_FORCE ) {
            return [ 'result' => 'locked' ];
        }
        $before = self::read( $id, $field );
        if ( $mode === self::MODE_FILL && ! self::is_empty( $before ) ) {
            return [ 'result' => 'not_empty' ];
        }
        $want = self::normalize( $field, $value );
        $lock_now = ! empty( $opts['lock'] ) && ! $locked && class_exists( 'MMI_Pipeline_Field_Locks' );
        if ( self::same( $before, $want ) ) {
            if ( $lock_now ) {
                MMI_Pipeline_Field_Locks::lock( $id, $lock_key );
            }
            return [ 'result' => 'unchanged', 'lock_added' => $lock_now ];
        }

        $err = self::put( $id, $field, $want );
        if ( $err !== '' ) {
            return [ 'result' => 'error', 'message' => $err ];
        }
        $after = self::read( $id, $field );

        if ( $lock_now ) {
            MMI_Pipeline_Field_Locks::lock( $id, $lock_key );
            $before['locks_added'] = [ MMI_Pipeline_Field_Locks::canonical( $lock_key ) ];
        }
        self::log( $id, $field, $before, $after, $source, (string) ( $opts['label'] ?? '' ) );
        do_action( 'mmi_pipeline_field_written', $id, $field, $after['value'], $before['value'], $source );
        return [ 'result' => 'written', 'lock_added' => $lock_now ];
    }

    /** The lock key a field is stored under. */
    public static function lock_key( string $field ): string {
        return self::LOCK_KEYS[ $field ] ?? $field;
    }

    /**
     * A field's current state: { field, exists, value }. Post fields and the
     * picture always exist; meta exists when stored; terms are sorted ids.
     */
    public static function read( int $id, string $field ): array {
        clean_post_cache( $id );
        if ( in_array( $field, self::POST_FIELDS, true ) ) {
            return [ 'field' => $field, 'exists' => true, 'value' => (string) get_post_field( $field, $id, 'raw' ) ];
        }
        if ( $field === '_thumbnail_id' ) {
            return [ 'field' => $field, 'exists' => true, 'value' => (int) get_post_thumbnail_id( $id ) ];
        }
        if ( strpos( $field, 'tax:' ) === 0 ) {
            $ids = wp_get_object_terms( $id, substr( $field, 4 ), [ 'fields' => 'ids' ] );
            $ids = is_wp_error( $ids ) ? [] : array_map( 'intval', $ids );
            sort( $ids );
            return [ 'field' => $field, 'exists' => true, 'value' => $ids ];
        }
        wp_cache_delete( $id, 'post_meta' );
        $exists = metadata_exists( 'post', $id, $field );
        return [ 'field' => $field, 'exists' => $exists, 'value' => $exists ? get_post_meta( $id, $field, true ) : null ];
    }

    /** Put a state from read() back, e.g. for Undo. Releases locks the write added. */
    public static function restore( int $id, array $state ): void {
        $field = (string) ( $state['field'] ?? '' );
        if ( $field === '' ) {
            return;
        }
        $value = ( ! array_key_exists( 'exists', $state ) || $state['exists'] ) ? ( $state['value'] ?? null ) : null;
        self::put( $id, $field, self::normalize( $field, $value ) );
        $release = (array) ( $state['locks_added'] ?? [] );
        if ( $release && class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
            MMI_Pipeline_Field_Locks::set( $id, array_diff( MMI_Pipeline_Field_Locks::get( $id ), $release ) );
        }
        clean_post_cache( $id );
    }

    /* ── Internals ─────────────────────────────────────────────────────── */

    private static function normalize( string $field, $value ) {
        if ( in_array( $field, self::POST_FIELDS, true ) ) {
            return (string) $value;
        }
        if ( $field === '_thumbnail_id' ) {
            return max( 0, (int) $value );
        }
        if ( strpos( $field, 'tax:' ) === 0 ) {
            $ids = array_values( array_unique( array_filter( array_map( 'intval', (array) $value ) ) ) );
            sort( $ids );
            return $ids;
        }
        return $value; // meta: as given; null deletes
    }

    private static function same( array $before, $want ): bool {
        if ( $want === null ) {
            return ! $before['exists'];
        }
        if ( ! $before['exists'] ) {
            return false;
        }
        if ( is_array( $want ) || is_array( $before['value'] ) ) {
            return $before['value'] == $want; // phpcs:ignore Universal.Operators.StrictComparisons -- array value equality.
        }
        return (string) $before['value'] === (string) $want;
    }

    private static function is_empty( array $state ): bool {
        if ( ! $state['exists'] ) {
            return true;
        }
        $v = $state['value'];
        return is_array( $v ) ? ! $v : ( is_string( $v ) ? trim( $v ) === '' : empty( $v ) );
    }

    /** The WordPress write for one field. @return string '' or an error message. */
    private static function put( int $id, string $field, $value ): string {
        if ( in_array( $field, self::POST_FIELDS, true ) ) {
            $slug = (string) get_post_field( 'post_name', $id, 'raw' );
            $res  = wp_update_post( wp_slash( [ 'ID' => $id, $field => (string) $value ] ), true );
            if ( is_wp_error( $res ) ) {
                return $res->get_error_message();
            }
            // A new title or text never moves the product's address: WordPress can
            // re-derive the slug of an unpublished product when its title changes.
            clean_post_cache( $id );
            if ( $field !== 'post_name' && $slug !== '' && (string) get_post_field( 'post_name', $id, 'raw' ) !== $slug ) {
                wp_update_post( [ 'ID' => $id, 'post_name' => $slug ] );
            }
            return '';
        }
        if ( $field === '_thumbnail_id' ) {
            if ( (int) $value > 0 ) {
                return set_post_thumbnail( $id, (int) $value ) ? '' : ( (int) get_post_thumbnail_id( $id ) === (int) $value ? '' : 'The picture could not be set.' );
            }
            delete_post_thumbnail( $id );
            return '';
        }
        if ( strpos( $field, 'tax:' ) === 0 ) {
            $res = wp_set_object_terms( $id, (array) $value, substr( $field, 4 ), false );
            return is_wp_error( $res ) ? $res->get_error_message() : '';
        }
        if ( $value === null ) {
            delete_post_meta( $id, $field );
            return '';
        }
        update_post_meta( $id, $field, is_string( $value ) ? wp_slash( $value ) : $value );
        return '';
    }

    /**
     * One change-log row per product and field per job (a second write in the
     * same job keeps the first "before"), in the source's open job.
     */
    private static function log( int $id, string $field, array $before, array $after, string $source, string $label ): void {
        if ( ! class_exists( Workbench_Change_Log::class ) ) {
            return;
        }
        Workbench_Change_Log::ensure_table();
        $job_id = self::open_job( $source, $label );
        $row    = Workbench_Change_Log::find_row( $job_id, $id, $field );
        if ( $row ) {
            if ( self::same( $row['before'], $after['exists'] ? $after['value'] : null ) ) {
                Workbench_Change_Log::delete_row( (int) $row['id'] ); // back where it started: nothing to undo
            } else {
                Workbench_Change_Log::update_after( (int) $row['id'], $after );
            }
        } else {
            Workbench_Change_Log::record( $job_id, [ [ 'product_id' => $id, 'field' => $field, 'before' => $before, 'after' => $after ] ] );
        }
        self::$touched[ $job_id ] = true;
        if ( ! self::$flush_hooked ) {
            self::$flush_hooked = true;
            add_action( 'shutdown', [ __CLASS__, 'flush' ] );
        }
    }

    /** The source's job for today, started if needed (a new one after an Undo of it). */
    private static function open_job( string $source, string $label ): string {
        $open = MMI_DB::get_setting( self::OPEN_JOBS, [] );
        $open = is_array( $open ) ? $open : [];
        $day  = wp_date( 'Y-m-d' );
        $have = $open[ $source ] ?? null;
        if ( $have && $have['day'] === $day ) {
            $job = Workbench_Change_Log::job( $have['job_id'] );
            if ( $job && $job['status'] === 'done' ) {
                return $have['job_id'];
            }
        }
        $job_id = substr( 'w:' . $source, 0, 26 ) . ':' . wp_date( 'ymdHis' );
        $info   = self::sources()[ $source ] ?? null;
        $name   = $info['label'] ?? $source;
        Workbench_Change_Log::save_job( [
            'id'           => $job_id,
            'kind'         => 'write',
            'created_at'   => current_time( 'mysql' ),
            'user'         => is_user_logged_in() ? wp_get_current_user()->display_name : 'Automatic',
            'action'       => self::ACTION,
            'params'       => [ 'source' => $source ],
            'summary'      => $label !== '' ? $label : sprintf( '%s wrote product fields', $name ),
            'filter_label' => sprintf( 'Written by %s', $name ),
            'total'        => 0,
            'cursor'       => 0,
            'changed'      => 0,
            'unchanged'    => 0,
            'failed'       => 0,
            'status'       => 'done',
            'undo_cursor'  => 0,
            'undone'       => 0,
            'conflicts'    => 0,
        ] );
        $open[ $source ] = [ 'job_id' => $job_id, 'day' => $day ];
        MMI_DB::set_setting( self::OPEN_JOBS, $open );
        return $job_id;
    }

    /** At the end of the request: each touched job's counts from its rows. */
    public static function flush(): void {
        foreach ( array_keys( self::$touched ) as $job_id ) {
            $job = Workbench_Change_Log::job( $job_id );
            if ( ! $job ) {
                continue;
            }
            $n              = Workbench_Change_Log::count_rows( $job_id );
            $job['total']   = $n;
            $job['cursor']  = $n;
            $job['changed'] = $n;
            Workbench_Change_Log::save_job( $job );
        }
        self::$touched = [];
        if ( class_exists( '\MannMade\DataPipeline\Workbench\Product_Workbench' ) ) {
            \MannMade\DataPipeline\Workbench\Product_Workbench::invalidate_search_cache();
        }
    }
}
