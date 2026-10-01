<?php
/**
 * Meta Key Discovery
 *
 * Sample-based discovery of scalar custom-field meta keys used by a given
 * post type — skips WP/plugin-internal keys and array/serialized values.
 * Extracted from the private method that already existed on
 * MMI_WP_Post_Type_Data_Type (Phase 3) so both that handler and the Export
 * Step 1 scope UI's "Custom Field Filters" datalist can share one
 * implementation instead of drifting apart.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Meta_Key_Discovery {

    private const SAMPLE_SIZE = 10;
    private const EXCLUDE_PREFIXES = [ '_edit_', '_wp_', '_thumbnail_id', '_encloseme' ];

    /**
     * @return string[] Discovered meta keys (unsorted, no guaranteed order).
     */
    public static function discover_for_post_type( string $post_type ): array {
        $sample_ids = get_posts( [
            'post_type'      => $post_type,
            'posts_per_page' => self::SAMPLE_SIZE,
            'fields'         => 'ids',
            'post_status'    => 'any',
        ] );

        $keys = [];
        foreach ( $sample_ids as $post_id ) {
            self::collect_scalar_keys( get_post_meta( $post_id ), $keys );
        }

        return array_keys( $keys );
    }

    /**
     * @param array<string,array> $meta Raw get_post_meta()-shaped map.
     * @param array<string,true>  $keys Accumulator, passed by reference.
     */
    private static function collect_scalar_keys( array $meta, array &$keys ): void {
        foreach ( $meta as $key => $values ) {
            if ( isset( $keys[ $key ] ) ) {
                continue;
            }
            foreach ( self::EXCLUDE_PREFIXES as $prefix ) {
                if ( strpos( $key, $prefix ) === 0 ) {
                    continue 2;
                }
            }
            $value = $values[0] ?? '';
            if ( is_serialized( $value ) || is_array( maybe_unserialize( $value ) ) ) {
                continue;
            }
            $keys[ $key ] = true;
        }
    }
}
