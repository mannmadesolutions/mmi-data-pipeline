<?php
/**
 * Export Writer Interface
 *
 * One implementation per output format (CSV in Phase 1; JSON/XML in later
 * phases). MMI_Dynamic_Data_Exporter drives whichever writer the profile's
 * chosen format resolves to — the exporter's batch loop never branches on
 * format itself.
 *
 * @package MannMade\DataPipeline\Exporters
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface MMI_Export_Writer {

    /**
     * Open (or re-open, for a resumed batch) the destination file for writing.
     *
     * @param string   $file_path Absolute path to the output file.
     * @param string[] $columns   Ordered list of output column names.
     * @param bool     $is_new    True on the first batch of a run (write header row);
     *                            false on subsequent batches (append only).
     */
    public function open( string $file_path, array $columns, bool $is_new ): void;

    /**
     * Append a single flat row (already resolved + transformed).
     *
     * @param array<string, mixed> $row
     */
    public function append_row( array $row ): void;

    /**
     * Close/flush the file handle. Must be safe to call even if open() was
     * never called (no-op).
     */
    public function close(): void;

    /** File extension this writer produces, without the leading dot. */
    public function get_extension(): string;

    /**
     * Called exactly once, after the true final batch of a run has been
     * written and closed — for formats that stream a root element/array
     * across batches (JSON/XML) and need to append its closing bracket/tag
     * only at the very end. A no-op for formats with no such wrapper (CSV).
     *
     * @param string $file_path Absolute path to the output file.
     */
    public function finalize( string $file_path ): void;
}
