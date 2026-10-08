<?php
/**
 * Product Content Registry — one read model for structured product content
 *
 * Product specs (system requirements, features, videos, licensing, …) reach
 * this store through several writers over the years: this plugin's
 * enrichment field mappings write `_mmi_*` JSON keys, while an older
 * JetEngine meta-box layer holds the same concepts in its own repeater
 * format. Templates, schema and health rules should not care which one a
 * given product happens to have — they ask this registry for a concept
 * ("requirements_mac") and get display-ready rows back, plus which meta key
 * supplied them (provenance).
 *
 * Source order per concept is deliberate, not "newest format wins":
 *  - Requirements/videos/features: `_mmi_*` first — pipeline-written, filled
 *    on ~3,200 products vs. ~500 for the JetEngine twins, and not
 *    title-cased the way the legacy `features` copies were.
 *  - Specs: the JetEngine `specs_table` only — `_mmi_specs_table` holds
 *    labels without values and is never read.
 *
 * Writes: every stored concept has one canonical `_mmi_*` key (its `store`
 * entry) and store() is the only writer the editor and migrations use, so
 * the storage shape lives in one place. Legacy JetEngine keys remain as
 * read-only fallbacks until they're migrated and retired.
 *
 * Reads go through get_post_meta(), so the post-meta cache WordPress primes
 * for the current product makes repeated lookups free.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Product_Content_Registry {

    /**
     * Concept key → definition.
     *   label    Human label (builder UIs, admin reports).
     *   group    requirements | media | features | commerce | notes
     *   type     list (rows) | text (single value)
     *   sources  Ordered [meta key, format, repeater sub-field?]. First non-empty wins.
     *   derive   [concept, limit] — used when no source has data.
     *   limit    Max rows returned.
     *   store    [canonical meta key, shape] — where store() writes. Absent for derived concepts.
     */
    const CONCEPTS = [
        'requirements_mac'     => [
            'label'   => 'Mac system requirements',
            'group'   => 'requirements',
            'type'    => 'list',
            'sources' => [ [ '_mmi_mac_requirements', 'requirements' ], [ 'mac-requirements', 'repeater', 'mac-requirement' ] ],
            'store'   => [ '_mmi_mac_requirements', 'requirements' ],
        ],
        'requirements_windows' => [
            'label'   => 'Windows system requirements',
            'group'   => 'requirements',
            'type'    => 'list',
            'sources' => [ [ '_mmi_windows_requirements', 'requirements' ], [ 'windows-requirements', 'repeater', 'windows-requirement' ] ],
            'store'   => [ '_mmi_windows_requirements', 'requirements' ],
        ],
        'requirements_linux'   => [
            'label'   => 'Linux system requirements',
            'group'   => 'requirements',
            'type'    => 'list',
            'sources' => [ [ '_mmi_linux_requirements', 'requirements' ], [ 'linux-requirements', 'repeater', 'linux-requirement' ] ],
            'store'   => [ '_mmi_linux_requirements', 'requirements' ],
        ],
        'features'             => [
            'label'   => 'Features',
            'group'   => 'features',
            'type'    => 'list',
            'sources' => [ [ '_mmi_features', 'list' ], [ 'features', 'repeater', 'feature' ] ],
            'store'   => [ '_mmi_features', 'list' ],
        ],
        // First 4 features, matching the Single Product design's original
        // "Key Features" block. Not top_features: those copies are Title-Cased
        // and carry escape damage, so they're exposed separately below.
        'key_features'         => [
            'label'   => 'Key features (first 4)',
            'group'   => 'features',
            'type'    => 'list',
            'sources' => [],
            'derive'  => 'features',
            'limit'   => 4,
        ],
        'top_features'         => [
            'label'   => 'Top features (curated)',
            'group'   => 'features',
            'type'    => 'list',
            'sources' => [ [ '_mmi_top_features', 'list', 'top_feature' ], [ 'top_features', 'repeater', 'top_feature' ] ],
            'store'   => [ '_mmi_top_features', 'list' ],
        ],
        'videos'               => [
            'label'   => 'Videos',
            'group'   => 'media',
            'type'    => 'list',
            'sources' => [ [ '_mmi_youtube_urls', 'videos' ], [ 'youtube_urls', 'repeater_videos', 'youtube_url' ] ],
            'store'   => [ '_mmi_youtube_urls', 'videos' ],
        ],
        'video_primary'        => [
            'label'   => 'Main video',
            'group'   => 'media',
            'type'    => 'list',
            'sources' => [],
            'derive'  => 'videos',
            'limit'   => 1,
        ],
        'specs'                => [
            'label'   => 'Specifications',
            'group'   => 'features',
            'type'    => 'list',
            // _mmi_specs_table (labels only, no values) is a broken historical copy — never read.
            'sources' => [ [ '_mmi_specs', 'pairs' ], [ 'specs_table', 'pairs' ] ],
            'store'   => [ '_mmi_specs', 'pairs' ],
        ],
        'licensing'            => [
            'label'   => 'Licensing & authorization',
            'group'   => 'commerce',
            'type'    => 'list',
            'sources' => [ [ '_mmi_licensing', 'licensing' ] ],
            'store'   => [ '_mmi_licensing', 'licensing' ],
        ],
        'platforms'            => [
            'label'   => 'Platforms',
            'group'   => 'requirements',
            'type'    => 'list',
            'sources' => [ [ '_mmi_platforms', 'platforms' ] ],
            'store'   => [ '_mmi_platforms', 'platforms' ],
        ],
        // User manuals and other documentation, as {label, url} links (owner 2026-10-08:
        // a Manual status in the Product Content coverage icons). Only http(s) URLs are stored.
        'manuals'              => [
            'label'   => 'Manuals & documentation',
            'group'   => 'media',
            'type'    => 'list',
            'sources' => [ [ '_mmi_manuals', 'links' ] ],
            'store'   => [ '_mmi_manuals', 'links' ],
        ],
        'disclaimer'           => [
            'label'   => 'Disclaimer',
            'group'   => 'notes',
            'type'    => 'text',
            'sources' => [ [ '_mmi_disclaimer', 'text' ], [ 'disclaimer', 'text' ] ],
            'store'   => [ '_mmi_disclaimer', 'text' ],
        ],
        'mpn'                  => [
            'label'   => 'Manufacturer part number',
            'group'   => 'commerce',
            'type'    => 'text',
            'sources' => [ [ 'mpn', 'text' ] ],
            'store'   => [ 'mpn', 'text' ],
        ],
    ];

    const PLUGIN_FORMAT_LABELS = [
        'vst-2'       => 'VST2',
        'vst-3'       => 'VST3',
        'au'          => 'AU',
        'aax'         => 'AAX',
        'rtas'        => 'RTAS',
        'stand-alone' => 'Standalone',
        'standalone'  => 'Standalone',
    ];

    const PLATFORM_LABELS = [ 'mac' => 'macOS', 'windows' => 'Windows', 'linux' => 'Linux' ];

    const LICENSING_LABELS = [
        'ilok'     => 'iLok',
        'computer' => 'Computer activation',
        'machine'  => 'Machine',
        'cloud'    => 'Cloud',
        'usb'      => 'USB dongle',
    ];

    const MEMO_MAX = 500;

    /** @var array<string,array> Per-request memo: "{product}:{concept}" → resolved result. */
    private static $memo = [];

    /**
     * Display-ready rows for a concept.
     *
     * @return array<int,array{value:string,label:string,text:string,id:string}>
     *   value  Full display line ("RAM: 4 GB")
     *   label  Row label, when the row has one ("RAM")
     *   text   Row value without the label ("4 GB")
     *   id     Machine id where one exists (YouTube video id)
     */
    public static function get_items( int $product_id, string $concept ): array {
        return self::resolve( $product_id, $concept )['items'];
    }

    /** Text concepts return their value; list concepts join their rows with ", ". */
    public static function get_text( int $product_id, string $concept ): string {
        return implode( ', ', array_column( self::get_items( $product_id, $concept ), 'value' ) );
    }

    public static function count( int $product_id, string $concept ): int {
        return count( self::get_items( $product_id, $concept ) );
    }

    /** Meta key the concept's rows came from ('' when empty) — provenance for reports and health rules. */
    public static function source( int $product_id, string $concept ): string {
        return self::resolve( $product_id, $concept )['source'];
    }

    public static function exists( string $concept ): bool {
        return isset( self::CONCEPTS[ $concept ] );
    }

    /** @return array<string,string> concept → label */
    public static function concepts(): array {
        return array_map( static fn( $def ) => $def['label'], self::CONCEPTS );
    }

    /* ── Resolution ───────────────────────────────────────────────────────── */

    private static function resolve( int $product_id, string $concept ): array {
        $empty = [ 'items' => [], 'source' => '' ];
        if ( $product_id <= 0 || ! isset( self::CONCEPTS[ $concept ] ) ) {
            return $empty;
        }
        $memo_key = $product_id . ':' . $concept;
        if ( isset( self::$memo[ $memo_key ] ) ) {
            return self::$memo[ $memo_key ];
        }

        $def    = self::CONCEPTS[ $concept ];
        $result = $empty;
        foreach ( $def['sources'] as $source ) {
            $items = self::read_source( $product_id, $source );
            if ( $items ) {
                $result = [ 'items' => $items, 'source' => $source[0] ];
                break;
            }
        }
        if ( ! $result['items'] && ! empty( $def['derive'] ) ) {
            $result = self::resolve( $product_id, $def['derive'] );
        }
        if ( ! empty( $def['limit'] ) ) {
            $result['items'] = array_slice( $result['items'], 0, (int) $def['limit'] );
        }

        // A page render touches one product; a feed/report loop touches
        // thousands. Bound the memo so the latter can't grow it without limit.
        if ( count( self::$memo ) >= self::MEMO_MAX ) {
            self::$memo = [];
        }
        return self::$memo[ $memo_key ] = $result;
    }

    private static function read_source( int $product_id, array $source ): array {
        [ $meta_key, $format ] = $source;
        $raw = get_post_meta( $product_id, $meta_key, true );
        if ( is_string( $raw ) && $raw !== '' && in_array( $format, [ 'requirements', 'list', 'videos', 'licensing', 'platforms', 'pairs', 'links', 'repeater', 'repeater_videos' ], true ) ) {
            $decoded = json_decode( $raw, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $raw = $decoded;
            }
        }

        switch ( $format ) {
            case 'requirements':
                return is_array( $raw ) ? self::requirements_rows( $raw ) : [];
            case 'list':
                return self::rows_from_strings( self::repeater_strings( $raw, $source[2] ?? '' ) );
            case 'repeater':
                return self::rows_from_strings( self::repeater_strings( $raw, $source[2] ?? '' ) );
            case 'repeater_videos':
                return self::video_rows( self::repeater_strings( $raw, $source[2] ?? '' ) );
            case 'videos':
                return self::video_rows( $raw );
            case 'licensing':
                return is_array( $raw ) ? self::licensing_rows( $raw ) : [];
            case 'platforms':
                return is_array( $raw ) ? self::rows_from_strings( array_map( static fn( $p ) => self::PLATFORM_LABELS[ strtolower( (string) $p ) ] ?? (string) $p, $raw ) ) : [];
            case 'pairs':
            case 'links':
                return is_array( $raw ) ? self::pair_rows( $raw ) : [];
            case 'text':
                $text = is_scalar( $raw ) ? trim( (string) $raw ) : '';
                return $text === '' ? [] : [ self::row( $text ) ];
        }
        return [];
    }

    private static function row( string $text, string $label = '', string $id = '' ): array {
        return [
            'value' => $label === '' ? $text : "{$label}: {$text}",
            'label' => $label,
            'text'  => $text,
            'id'    => $id,
        ];
    }

    /**
     * Sub-field values out of a JetEngine repeater. Handles every shape found
     * live: `item-N` keyed arrays, plain lists, stdClass rows, and rows whose
     * value is itself a serialized repeater (double-serialized by an old import).
     */
    private static function repeater_strings( $raw, string $field, int $depth = 0 ): array {
        if ( $depth > 3 ) {
            return [];
        }
        if ( is_string( $raw ) ) {
            return is_serialized( $raw ) ? self::repeater_strings( maybe_unserialize( $raw ), $field, $depth + 1 ) : [ $raw ];
        }
        if ( is_object( $raw ) ) {
            $raw = get_object_vars( $raw );
        }
        if ( ! is_array( $raw ) ) {
            return is_scalar( $raw ) ? [ (string) $raw ] : [];
        }
        if ( $field !== '' && array_key_exists( $field, $raw ) ) {
            return self::repeater_strings( $raw[ $field ], $field, $depth + 1 );
        }
        $out = [];
        foreach ( $raw as $row ) {
            $out = array_merge( $out, self::repeater_strings( $row, $field, $depth + 1 ) );
        }
        return $out;
    }

    /**
     * Repair `\u2019`-style escapes whose backslash was stripped by
     * update_post_meta()'s wp_unslash() ("Avidu2019s", "Emulation u2013
     * Captures"). Limited to the punctuation code points that damage actually
     * contains (dashes, quotes, bullet, ellipsis, primes) so it can match
     * inside words without touching real text.
     */
    private static function repair_escapes( string $text ): string {
        $repaired = preg_replace_callback(
            '/(?<!\\\\)u(201[0-9a-fA-F]|2022|2026|203[23])/',
            static fn( $m ) => html_entity_decode( '&#x' . $m[1] . ';', ENT_QUOTES, 'UTF-8' ),
            $text
        );
        return $repaired ?? $text;
    }

    private static function rows_from_strings( array $values ): array {
        $rows = [];
        foreach ( $values as $value ) {
            $text = is_scalar( $value ) ? self::repair_escapes( trim( wp_strip_all_tags( (string) $value ) ) ) : '';
            if ( $text !== '' ) {
                $rows[] = self::row( $text );
            }
        }
        return $rows;
    }

    /**
     * XChange Web Assets requirements object → rows. Field meanings per the
     * vendor schema: ram/disk are GB ints where 0 means "not stated";
     * `plugins` are host formats; `support` is bitness.
     */
    private static function requirements_rows( array $req ): array {
        $rows    = [];
        $version = trim( (string) ( $req['version'] ?? '' ) );
        $notes   = trim( (string) ( $req['notes'] ?? '' ) );
        if ( $version !== '' ) {
            $rows[] = self::row( $notes !== '' ? "{$version} ({$notes})" : $version, 'OS' );
        } elseif ( $notes !== '' ) {
            $rows[] = self::row( $notes );
        }

        if ( trim( (string) ( $req['cpu'] ?? '' ) ) !== '' ) {
            $rows[] = self::row( trim( (string) $req['cpu'] ), 'CPU' );
        }
        foreach ( [ 'ram' => 'RAM', 'disk' => 'Disk space' ] as $key => $label ) {
            $amount = (float) ( $req[ $key ] ?? 0 );
            if ( $amount > 0 ) {
                $rows[] = self::row( rtrim( rtrim( number_format( $amount, 1, '.', '' ), '0' ), '.' ) . ' GB', $label );
            }
        }

        $formats = array_unique( array_filter( array_map(
            static fn( $f ) => self::PLUGIN_FORMAT_LABELS[ strtolower( (string) $f ) ] ?? ( strtolower( (string) $f ) === 'other' ? '' : (string) $f ),
            (array) ( $req['plugins'] ?? [] )
        ) ) );
        if ( $formats ) {
            $rows[] = self::row( implode( ', ', $formats ), 'Formats' );
        }

        $bits = array_map( static fn( $b ) => str_replace( '_bit', '-bit', (string) $b ), (array) ( $req['support'] ?? [] ) );
        if ( $bits ) {
            $rows[] = self::row( implode( ' & ', $bits ), 'Architecture' );
        }

        foreach ( [ 'audio_card' => 'Audio interface', 'ports' => 'Ports' ] as $key => $label ) {
            if ( trim( (string) ( $req[ $key ] ?? '' ) ) !== '' ) {
                $rows[] = self::row( trim( (string) $req[ $key ] ), $label );
            }
        }
        if ( ! empty( $req['internet_required'] ) ) {
            $rows[] = self::row( 'Internet connection required for activation/use' );
        }

        // Free-text extras, one row per line. A few feed records also carry
        // positional (numeric-key) strings; treat them the same way.
        $extras = [ (string) ( $req['additional_requirements'] ?? '' ) ];
        foreach ( $req as $key => $value ) {
            if ( is_int( $key ) || ctype_digit( (string) $key ) ) {
                $extras[] = is_scalar( $value ) ? (string) $value : '';
            }
        }
        foreach ( $extras as $extra ) {
            foreach ( preg_split( '/\R+/', $extra ) as $line ) {
                $line = trim( wp_strip_all_tags( $line ) );
                if ( $line !== '' ) {
                    $rows[] = self::row( $line );
                }
            }
        }

        return $rows;
    }

    /** `{main, extras[]}` (pipeline), a plain URL list, or a bare string → rows with the YouTube id. */
    private static function video_rows( $raw ): array {
        if ( is_string( $raw ) ) {
            $raw = [ $raw ];
        }
        if ( ! is_array( $raw ) ) {
            return [];
        }
        $urls = isset( $raw['main'] ) || isset( $raw['extras'] )
            ? array_merge( [ $raw['main'] ?? '' ], (array) ( $raw['extras'] ?? [] ) )
            : array_values( $raw );

        $rows = [];
        $seen = [];
        foreach ( $urls as $url ) {
            $url = is_scalar( $url ) ? trim( (string) $url ) : '';
            $id  = self::youtube_id( $url );
            if ( $id === '' || isset( $seen[ $id ] ) ) {
                continue;
            }
            $seen[ $id ] = true;
            $rows[]      = self::row( 'https://www.youtube.com/watch?v=' . $id, '', $id );
        }
        return $rows;
    }

    public static function youtube_id( string $url ): string {
        if ( preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~', $url, $m ) ) {
            $id = $m[1];
        } elseif ( preg_match( '/^[A-Za-z0-9_-]{11}$/', $url ) ) {
            $id = $url;
        } else {
            return '';
        }
        // Real ids are random base64 (mixed case, digits). An id made only of
        // lowercase letters and hyphens ("frame-error", "intersector") is a
        // slug placeholder that embeds as "Video unavailable" — found live in
        // legacy youtube_urls data. A real id matching this is ~1 in 20,000.
        return preg_match( '/^[a-z]+(?:-[a-z]+)*$/', $id ) ? '' : $id;
    }

    /** `{methods[], ilok[]}` → "iLok (Cloud, USB dongle)", "Computer activation", … */
    private static function licensing_rows( array $lic ): array {
        $label = static fn( $v ) => self::LICENSING_LABELS[ strtolower( (string) $v ) ] ?? ucfirst( (string) $v );
        $ilok  = array_map( $label, (array) ( $lic['ilok'] ?? [] ) );
        $rows  = [];
        foreach ( (array) ( $lic['methods'] ?? [] ) as $method ) {
            $method = strtolower( (string) $method );
            if ( $method === 'ilok' ) {
                $rows[] = self::row( $ilok ? 'iLok (' . implode( ', ', $ilok ) . ')' : 'iLok' );
            } elseif ( $method !== '' ) {
                $rows[] = self::row( $label( $method ) );
            }
        }
        if ( ! $rows && $ilok ) {
            $rows[] = self::row( 'iLok (' . implode( ', ', $ilok ) . ')' );
        }
        return $rows;
    }

    /** JetEngine specs_table `[{spec_label, spec_value}]` → labelled rows. */
    private static function pair_rows( array $pairs ): array {
        $rows = [];
        foreach ( array_values( $pairs ) as $pair ) {
            $pair  = (array) $pair;
            $label = trim( (string) ( $pair['label'] ?? $pair['spec_label'] ?? '' ) );
            $value = trim( wp_strip_all_tags( (string) ( $pair['value'] ?? $pair['spec_value'] ?? '' ) ) );
            if ( $value !== '' ) {
                $rows[] = self::row( $value, $label );
            }
        }
        return $rows;
    }

    /* ── Writes ───────────────────────────────────────────────────────────── */

    /** XChange Web Assets requirements schema — the only keys a requirements object may hold. */
    const REQUIREMENT_TEXT_FIELDS = [ 'version', 'notes', 'cpu', 'audio_card', 'ports', 'additional_requirements' ];
    const REQUIREMENT_BITS        = [ '32_bit', '64_bit' ];
    const LICENSING_METHODS       = [ 'ilok', 'computer', 'cloud', 'usb' ];
    const ILOK_TYPES              = [ 'machine', 'cloud', 'usb' ];

    /** Concepts that have a canonical storage key (everything except derived ones). */
    public static function storable_concepts(): array {
        return array_keys( array_filter( self::CONCEPTS, static fn( $def ) => ! empty( $def['store'] ) ) );
    }

    public static function canonical_key( string $concept ): string {
        return self::CONCEPTS[ $concept ]['store'][0] ?? '';
    }

    /**
     * The concept's current canonical value, decoded into its storage shape
     * (requirements object, string list, {main, extras}, pairs, licensing
     * object, string) — what an editor prefills. Only the canonical key is
     * read; legacy fallbacks are not.
     *
     * @return mixed
     */
    public static function canonical_value( int $product_id, string $concept ) {
        [ $key, $shape ] = self::CONCEPTS[ $concept ]['store'] ?? [ '', '' ];
        if ( $key === '' ) {
            return null;
        }
        $raw = get_post_meta( $product_id, $key, true );
        if ( $shape === 'text' ) {
            return is_scalar( $raw ) ? (string) $raw : '';
        }
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            $raw     = json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
        }
        switch ( $shape ) {
            case 'list':
            case 'platforms':
                $lines = array_map( static fn( $v ) => self::repair_escapes( trim( $v ) ), self::repeater_strings( $raw, $concept === 'top_features' ? 'top_feature' : '' ) );
                return array_values( array_filter( $lines, 'strlen' ) );
            case 'videos':
                return array_column( self::video_rows( $raw ), 'value' );
            case 'pairs':
            case 'links':
                return array_map( static fn( $r ) => [ 'label' => $r['label'], 'value' => $r['text'] ], is_array( $raw ) ? self::pair_rows( $raw ) : [] );
            default:
                return is_array( $raw ) ? $raw : [];
        }
    }

    /**
     * Write a concept's canonical value. $value is in the storage shape
     * canonical_value() returns; it's normalized (unknown keys dropped,
     * empty rows removed, junk video ids rejected) before writing. An empty
     * result deletes the meta row.
     *
     * JSON is slashed before update_post_meta(): that function runs
     * wp_unslash() on its input, which turns a JSON "–" into "u2013".
     * That is the root cause of the escape damage found in `_mmi_top_features`.
     *
     * @return bool True when the stored value changed.
     */
    public static function store( int $product_id, string $concept, $value ): bool {
        [ $key, $shape ] = self::CONCEPTS[ $concept ]['store'] ?? [ '', '' ];
        if ( $key === '' || $product_id <= 0 ) {
            return false;
        }

        $normalized = self::normalize_for_storage( $shape, $value );
        $encoded    = $shape === 'text'
            ? (string) $normalized
            : ( $normalized ? wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : '' );

        $current = get_post_meta( $product_id, $key, true );
        self::forget( $product_id );

        if ( $encoded === '' ) {
            if ( metadata_exists( 'post', $product_id, $key ) ) {
                delete_post_meta( $product_id, $key );
                return true;
            }
            return false;
        }
        if ( is_string( $current ) && $current === $encoded ) {
            return false;
        }
        update_post_meta( $product_id, $key, wp_slash( $encoded ) );
        return true;
    }

    /**
     * Rows resolved from any source → the concept's storage shape. Used to
     * migrate a legacy JetEngine value into its canonical key.
     *
     * @return mixed
     */
    public static function canonical_from_rows( string $concept, array $rows ) {
        $shape = self::CONCEPTS[ $concept ]['store'][1] ?? '';
        switch ( $shape ) {
            case 'requirements':
                // Legacy rows are free text ("macOS 10.13 or later"); each becomes one line.
                return [ 'additional_requirements' => implode( "\n", array_column( $rows, 'value' ) ) ];
            case 'list':
            case 'platforms':
            case 'videos':
                return array_column( $rows, 'value' );
            case 'pairs':
            case 'links':
                return array_map( static fn( $r ) => [ 'label' => $r['label'], 'value' => $r['text'] ], $rows );
            case 'text':
                return (string) ( $rows[0]['value'] ?? '' );
        }
        return null;
    }

    /**
     * Whether $value would change what's stored, compared after normalizing
     * both sides. An editor re-posting the prefilled value must not count as
     * an edit (the stored value may predate normalization, e.g. "VST-3" vs
     * "vst-3", or `"ram": 0`), or every first save would trigger a Field Lock.
     */
    public static function differs_from_stored( int $product_id, string $concept, $value ): bool {
        $shape = self::CONCEPTS[ $concept ]['store'][1] ?? '';
        if ( $shape === '' ) {
            return false;
        }
        return self::normalize_for_storage( $shape, $value ) != self::normalize_for_storage( $shape, self::canonical_value( $product_id, $concept ) ); // phpcs:ignore Universal.Operators.StrictComparisons -- ram/disk int vs float must compare equal.
    }

    /** Drop memoized reads for one product after a write. */
    public static function forget( int $product_id ): void {
        foreach ( array_keys( self::CONCEPTS ) as $concept ) {
            unset( self::$memo[ $product_id . ':' . $concept ] );
        }
    }

    /** @return mixed Normalized storage value; empty array/string when nothing survives. */
    private static function normalize_for_storage( string $shape, $value ) {
        $clean_line = static fn( $v ) => is_scalar( $v ) ? self::repair_escapes( trim( sanitize_textarea_field( (string) $v ) ) ) : '';

        switch ( $shape ) {
            case 'text':
                return is_scalar( $value ) ? trim( sanitize_textarea_field( (string) $value ) ) : '';

            case 'list':
                return array_values( array_filter( array_map( $clean_line, (array) $value ), 'strlen' ) );

            case 'platforms':
                $allowed = array_keys( self::PLATFORM_LABELS );
                return array_values( array_intersect( $allowed, array_map( 'strtolower', array_map( 'strval', (array) $value ) ) ) );

            case 'videos':
                $urls = array_column( self::video_rows( array_values( (array) $value ) ), 'value' );
                return $urls ? [ 'main' => array_shift( $urls ), 'extras' => $urls ] : [];

            case 'pairs':
                $pairs = [];
                foreach ( (array) $value as $pair ) {
                    $pair  = (array) $pair;
                    $label = $clean_line( $pair['label'] ?? '' );
                    $text  = $clean_line( $pair['value'] ?? '' );
                    if ( $text !== '' ) {
                        $pairs[] = [ 'label' => $label, 'value' => $text ];
                    }
                }
                return $pairs;

            case 'links':
                $links = [];
                foreach ( (array) $value as $link ) {
                    $link = (array) $link;
                    $raw  = trim( (string) ( $link['value'] ?? '' ) );
                    $url  = preg_match( '#^https?://#i', $raw ) ? esc_url_raw( $raw, [ 'http', 'https' ] ) : ''; // esc_url_raw() alone turns "not a url" into http://not%20a%20url
                    if ( $url !== '' ) {
                        $links[] = [ 'label' => $clean_line( $link['label'] ?? '' ), 'value' => $url ];
                    }
                }
                return $links;

            case 'licensing':
                $value   = (array) $value;
                $methods = array_values( array_intersect( self::LICENSING_METHODS, array_map( 'strtolower', array_map( 'strval', (array) ( $value['methods'] ?? [] ) ) ) ) );
                $ilok    = array_values( array_intersect( self::ILOK_TYPES, array_map( 'strtolower', array_map( 'strval', (array) ( $value['ilok'] ?? [] ) ) ) ) );
                return array_filter( [ 'methods' => $methods, 'ilok' => in_array( 'ilok', $methods, true ) ? $ilok : [] ] );

            case 'requirements':
                $value = (array) $value;
                $out   = [];
                foreach ( self::REQUIREMENT_TEXT_FIELDS as $field ) {
                    $text = $field === 'additional_requirements'
                        ? trim( sanitize_textarea_field( (string) ( $value[ $field ] ?? '' ) ) )
                        : $clean_line( $value[ $field ] ?? '' );
                    if ( $text !== '' ) {
                        $out[ $field ] = $text;
                    }
                }
                foreach ( [ 'ram', 'disk' ] as $field ) {
                    $amount = (float) ( $value[ $field ] ?? 0 );
                    if ( $amount > 0 ) {
                        $out[ $field ] = floor( $amount ) === $amount ? (int) $amount : $amount;
                    }
                }
                $plugins = array_values( array_intersect( array_merge( array_keys( self::PLUGIN_FORMAT_LABELS ), [ 'other' ] ), array_map( 'strtolower', array_map( 'strval', (array) ( $value['plugins'] ?? [] ) ) ) ) );
                $bits    = array_values( array_intersect( self::REQUIREMENT_BITS, array_map( 'strtolower', array_map( 'strval', (array) ( $value['support'] ?? [] ) ) ) ) );
                if ( $plugins ) {
                    $out['plugins'] = $plugins;
                }
                if ( $bits ) {
                    $out['support'] = $bits;
                }
                if ( ! empty( $value['internet_required'] ) ) {
                    $out['internet_required'] = true;
                }
                return $out;
        }
        return [];
    }
}
