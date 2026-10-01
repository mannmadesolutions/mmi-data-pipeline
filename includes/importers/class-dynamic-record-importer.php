<?php
/**
 * Dynamic Record Importer (Phase 2 — generalized import foundation)
 *
 * Structural sibling of MMI_Dynamic_Product_Importer, used for
 * data_type !== 'product' import profiles. Deliberately a NEW, separate
 * orchestrator rather than a refactor of the 1200+ line product importer —
 * see the plan (spicy-sparking-llama.md section 4) for why: the product
 * importer's control flow interleaves promo-injection, variable-product
 * branching, and stock-override checks tightly enough that extracting a
 * clean generic base would risk regressing the product import path, which
 * must not happen. This class reuses, unchanged: Data_Source_Manager's
 * already-fetched {supplier}-products.json convention for fetch,
 * MMI_Pipeline_Field_Resolver for path-walking + transforms, and
 * Condition_Evaluator for conditional field application — all three were
 * already 100% data-type-agnostic. Only the CRUD step is new, and it's
 * delegated entirely to the profile's MMI_Data_Type_Handler.
 *
 * Scope note: this is the "foundation" the plan calls for — proven here via
 * direct instantiation/testing, not yet wired into the Import tab's UI
 * (which remains 100% product-scoped, per Phase 1's explicit "do not touch"
 * decision on the existing product import wizard/modal).
 *
 * Field mapping shape for non-product import profiles is simpler than
 * product's per-supplier 'source' sub-array: each field's saved config is
 * `['enabled' => bool, 'source' => 'dot.notation.path', 'transform' => ...,
 * 'transform_params' => [...], 'conditions' => [...]]` — a single source
 * path per field, since there's no per-supplier field-mapping UI for
 * generic data types yet (a future phase can add one, generalizing
 * panel-field-mapping.php the same way the export side's field mapping
 * was generalized — see export-preview.js's Columns popover, which now
 * doubles as the export field-mapping editor).
 *
 * @package MannMade\DataPipeline\Importers
 */

namespace MannMade\DataPipeline\Importers;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Dynamic_Record_Importer {

    private string $supplier_name;
    private string $profile;
    private string $data_type;
    private string $json_file;

    private array $field_mappings = [];
    private string $primary_key_field = 'ID';
    private ?\MMI_Data_Type_Handler $handler = null;

    private array $stats = [ 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => 0, 'skipped' => 0 ];
    private array $log_entries = [];
    private int $total_in_feed = 0;
    private int $next_offset = 0;
    private bool $has_more = false;

    public function __construct( string $supplier, string $profile, string $data_type ) {
        $this->supplier_name = $supplier;
        $this->profile        = $profile;
        $this->data_type      = $data_type;

        $this->handler         = \MMI_Data_Type_Registry::get( $data_type );
        $this->field_mappings   = \MMI_Pipeline_Field_Schema_Resolver::get_effective( $profile, $data_type, 'import' );
        $this->primary_key_field = \MMI_DB::get_primary_key( $supplier, 'wc', self::default_primary_key_field( $data_type ) );

        $json_dir       = mmi_shared_lib_json_dir();
        $this->json_file = $json_dir . $supplier . '-products.json';
    }

    /**
     * Sensible default primary-key field per data type, used only when the
     * profile hasn't explicitly configured one via MMI_DB::set_primary_key().
     * A bare 'ID' (WordPress's own row ID) is never present in an external
     * feed, so defaulting to it for every data type — as a first draft of
     * this class did — silently breaks update-matching (every re-import
     * looks like a new record, which then fails on the second run for any
     * data type with a uniqueness constraint, e.g. WP_User's email/login).
     */
    private static function default_primary_key_field( string $data_type ): string {
        switch ( $data_type ) {
            case 'customer':
                return 'user_email';
            case 'order':
                return 'ID';
            default:
                // A taxonomy term has no natural 'ID' in an external feed either —
                // confirmed live while wiring Milestone 4 (DATA_PIPELINE_PHASE2_SCOPING.md)
                // against the taxonomy pilot: MMI_WP_Taxonomy_Data_Type::find_existing_id()
                // already treats any field OTHER than the literal 'term_id' as a
                // slug/name lookup (get_term_by()), so 'slug' — not 'ID' — is the
                // correct default for every 'taxonomy:*' data type, same reasoning
                // as the 'customer' case above. Other generic types (cpt:*, post,
                // comment, user) haven't been piloted yet and are left at 'ID'
                // rather than guessed at.
                if ( strpos( $data_type, 'taxonomy:' ) === 0 ) {
                    return 'slug';
                }
                return 'ID';
        }
    }

    /**
     * Count records available in a supplier's feed file, without
     * instantiating a full importer or resolving field mappings — backs
     * Review & Compare's generic-preview bypass (Milestone 5,
     * DATA_PIPELINE_PHASE2_SCOPING.md): a plain "N records" count instead of
     * a full per-row diff, which that doc explicitly recommends over
     * building a second 1700-line preview system for an unproven data type.
     * Deliberately static and standalone rather than instantiating a real
     * importer just to read total_in_feed off its results — no field
     * mappings/handler/primary-key are needed to answer "how many rows are
     * in this file."
     */
    public static function count_feed_items( string $supplier ): int {
        $json_dir  = mmi_shared_lib_json_dir();
        $json_file = $json_dir . $supplier . '-products.json';

        if ( ! file_exists( $json_file ) ) {
            return 0;
        }

        $data = json_decode( file_get_contents( $json_file ), true );
        if ( ! is_array( $data ) ) {
            return 0;
        }

        // Same wrapped/bare tolerance as extract_items() below.
        if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
            return count( $data['products'] );
        }
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            return count( $data['items'] );
        }
        return array_is_list( $data ) ? count( $data ) : 1;
    }

    private function log( string $message, string $type = 'info' ): void {
        $this->log_entries[] = [ 'message' => $message, 'type' => $type, 'time' => current_time( 'mysql' ) ];
        if ( $type === 'error' ) {
            \MMI_Logger::error( $message, [ 'profile' => $this->profile, 'data_type' => $this->data_type ], 'data-pipeline', 'MMI_Dynamic_Record_Importer' );
        } else {
            \MMI_Logger::info( $message, [ 'profile' => $this->profile, 'data_type' => $this->data_type ], 'data-pipeline', 'MMI_Dynamic_Record_Importer' );
        }
    }

    /**
     * Extract the flat item list from whatever wrapper shape the fetched
     * JSON uses — mirrors MMI_Dynamic_Product_Importer::extract_items()'s
     * tolerance for both wrapped ({"products":[...]}) and bare ([...]) feeds.
     */
    private function extract_items( array $data ): array {
        if ( isset( $data['products'] ) && is_array( $data['products'] ) ) {
            return $data['products'];
        }
        if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
            return $data['items'];
        }
        return array_is_list( $data ) ? $data : [ $data ];
    }

    /**
     * Mirrors MMI_Dynamic_Product_Importer::run()'s signature exactly.
     */
    public function run( int $offset = 0, ?int $limit = null, ?float $time_budget_seconds = null, ?int $memory_budget_bytes = null ): array {
        $start_time = microtime( true );

        if ( ! $this->handler ) {
            $this->log( "No handler registered for data type '{$this->data_type}'", 'error' );
            $this->has_more = false;
            return $this->get_results();
        }

        if ( ! file_exists( $this->json_file ) ) {
            $this->log( "ERROR: JSON file not found: {$this->json_file}", 'error' );
            $this->total_in_feed = 0;
            $this->next_offset   = $offset;
            $this->has_more      = false;
            return $this->get_results();
        }

        $json_content = file_get_contents( $this->json_file );
        $data         = json_decode( $json_content, true );
        unset( $json_content );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $this->log( 'ERROR: Invalid JSON in file: ' . json_last_error_msg(), 'error' );
            $this->total_in_feed = 0;
            $this->next_offset   = $offset;
            $this->has_more      = false;
            return $this->get_results();
        }

        $items = $this->extract_items( is_array( $data ) ? $data : [] );
        unset( $data );

        $this->total_in_feed = count( $items );
        $slice = ( $limit !== null ) ? array_slice( $items, $offset, $limit ) : array_slice( $items, $offset );
        unset( $items );

        $processed = 0;
        foreach ( $slice as $item ) {
            if ( $time_budget_seconds !== null && ( microtime( true ) - $start_time ) >= $time_budget_seconds ) {
                break;
            }
            // $processed > 0 guards against checking before this call has done
            // any work at all — $processed % 25 === 0 is also true at
            // $processed === 0, so without this guard a worker whose BASELINE
            // memory (before run() does anything) already sits at or above
            // the budget breaks on every single call, forever, regardless of
            // real per-item cost — confirmed live via wp eval while
            // verifying Milestone 4 (DATA_PIPELINE_PHASE2_SCOPING.md): a
            // long-lived WP-CLI process with ~247MB baseline usage never
            // processed a single record against the default 200MB batch
            // budget. MMI_Dynamic_Product_Importer::run() has the identical
            // unguarded pattern — not changed here, since fixing a shared-
            // shape bug in the battle-tested Product path is out of this
            // milestone's scope and PHP-FPM's typically-lower baseline
            // usage there means it has likely never been observed to trip.
            if ( $memory_budget_bytes !== null && $processed > 0 && $processed % 25 === 0 && memory_get_usage( true ) >= $memory_budget_bytes ) {
                $this->log( 'Memory budget reached — pausing batch.', 'warning' );
                break;
            }

            try {
                $this->process_item( $item );
            } catch ( \Throwable $e ) {
                $this->stats['errors']++;
                $this->log( 'Record import failed: ' . $e->getMessage(), 'error' );
            }

            $processed++;
        }

        $this->next_offset = $offset + $processed;
        $this->has_more     = $this->next_offset < $this->total_in_feed;

        $this->log( "Imported batch: {$processed} record(s), offset {$offset} -> {$this->next_offset} of {$this->total_in_feed}" );

        return $this->get_results();
    }

    private function process_item( array $item ): void {
        $data = [];
        foreach ( $this->field_mappings as $field => $config ) {
            if ( empty( $config['enabled'] ) ) {
                continue;
            }

            $source_path = $config['source'] ?? '';
            if ( $source_path === '' ) {
                continue;
            }

            $params = is_array( $config['transform_params'] ?? null ) ? $config['transform_params'] : [];
            $value  = \MMI_Pipeline_Field_Resolver::resolve_field_value( $item, $source_path, $config['transform'] ?? 'none', $params );

            if ( ! empty( $config['conditions'] ) && is_array( $config['conditions'] ) ) {
                $fallback     = (string) ( $config['condition_fallback_value'] ?? '' );
                $use_fallback = ! empty( $config['condition_fallback_enabled'] );
                $cond_result  = Condition_Evaluator::evaluate_conditions(
                    $value, $config['conditions'], $fallback, $use_fallback,
                    (array) $item, 0, (string) ( $config['condition_match_logic'] ?? 'all' )
                );
                if ( ! $cond_result['pass'] && ! $use_fallback ) {
                    continue;
                }
                $value = $cond_result['value'];
            }

            if ( ( $value === null || $value === '' ) && isset( $config['default_value'] ) ) {
                $value = $config['default_value'];
            }

            $data[ $field ] = $value;
        }

        if ( empty( $data ) ) {
            $this->stats['skipped']++;
            return;
        }

        $primary_value = $data[ $this->primary_key_field ] ?? null;
        $existing_id   = $primary_value !== null
            ? $this->handler->find_existing_id( $this->primary_key_field, $primary_value )
            : null;

        $result = $this->handler->upsert_record( $data, $existing_id );

        if ( ! $result['success'] ) {
            $this->stats['errors']++;
            $this->log( 'Upsert failed: ' . ( $result['message'] ?? 'unknown error' ), 'error' );
            return;
        }

        if ( $result['action'] === 'create' ) {
            $this->stats['created']++;
        } elseif ( $result['action'] === 'unchanged' ) {
            $this->stats['unchanged']++;
        } else {
            $this->stats['updated']++;
        }
    }

    public function get_results(): array {
        return [
            'success'       => true,
            'supplier'      => $this->supplier_name,
            'profile'       => $this->profile,
            'data_type'     => $this->data_type,
            'stats'         => $this->stats,
            'log'           => $this->log_entries,
            'message'       => "Import completed: {$this->stats['created']} created, {$this->stats['updated']} updated, {$this->stats['unchanged']} unchanged",
            'total_in_feed' => $this->total_in_feed,
            'next_offset'   => $this->next_offset,
            'has_more'      => $this->has_more,
        ];
    }
}
