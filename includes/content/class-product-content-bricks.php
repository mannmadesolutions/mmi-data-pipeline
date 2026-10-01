<?php
/**
 * Bricks Builder integration for the Product Content Registry
 *
 * Exposes every registry concept to Bricks without templates having to know
 * which meta key or storage format holds it:
 *
 *   Query loop types   "MMI Spec: {label}" (objectType mmi_spec_{concept}) —
 *                      one loop item per row, like JetEngine's per-field
 *                      repeater query types, so an existing JetEngine loop
 *                      converts by changing objectType alone.
 *   Loop tags          {mmi_spec_item} "RAM: 4 GB" · {mmi_spec_item_label} "RAM"
 *                      {mmi_spec_item_text} "4 GB" · {mmi_spec_item_id} (YouTube id)
 *   Product tags       {mmi_spec_{concept}}        all rows joined with ", "
 *                      {mmi_spec_{concept}_count}  row count (for conditions)
 *
 * Only active when the Bricks theme is running.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Product_Content_Bricks {

    const QUERY_PREFIX = 'mmi_spec_';
    const TAG_GROUP    = 'MMI Product Content';

    /** Contexts whose output Bricks uses as a URL/value, not HTML text — left unescaped. */
    const RAW_CONTEXTS = [ 'link', 'image', 'media', 'url' ];

    /** Loop-scoped tag → row field. */
    const ITEM_TAGS = [
        'mmi_spec_item'       => [ 'value', 'Spec row (loop)' ],
        'mmi_spec_item_label' => [ 'label', 'Spec row label (loop)' ],
        'mmi_spec_item_text'  => [ 'text', 'Spec row value (loop)' ],
        'mmi_spec_item_id'    => [ 'id', 'Spec row id, e.g. YouTube id (loop)' ],
    ];

    public static function init(): void {
        add_action( 'after_setup_theme', [ __CLASS__, 'maybe_register' ], 20 );
    }

    public static function maybe_register(): void {
        if ( ! defined( 'BRICKS_VERSION' ) ) {
            return;
        }
        add_filter( 'bricks/setup/control_options', [ __CLASS__, 'register_query_types' ] );
        add_filter( 'bricks/query/run', [ __CLASS__, 'run_query' ], 10, 2 );
        add_filter( 'bricks/query/loop_object_id', [ __CLASS__, 'loop_object_id' ], 10, 3 );
        add_filter( 'bricks/query/loop_object_type', [ __CLASS__, 'loop_object_type' ], 10, 3 );
        add_filter( 'bricks/dynamic_tags_list', [ __CLASS__, 'register_tags' ] );
        add_filter( 'bricks/dynamic_data/render_tag', [ __CLASS__, 'render_tag' ], 20, 3 );
        add_filter( 'bricks/dynamic_data/render_content', [ __CLASS__, 'render_content' ], 20, 3 );
        add_filter( 'bricks/frontend/render_data', [ __CLASS__, 'render_content' ], 20, 2 );
    }

    /* ── Query loops ──────────────────────────────────────────────────────── */

    public static function register_query_types( array $options ): array {
        foreach ( MMI_Product_Content_Registry::CONCEPTS as $concept => $def ) {
            if ( $def['type'] === 'list' ) {
                $options['queryTypes'][ self::QUERY_PREFIX . $concept ] = 'MMI Spec: ' . $def['label'];
            }
        }
        return $options;
    }

    private static function concept_for_object_type( string $object_type ): string {
        if ( strpos( $object_type, self::QUERY_PREFIX ) !== 0 ) {
            return '';
        }
        $concept = substr( $object_type, strlen( self::QUERY_PREFIX ) );
        return MMI_Product_Content_Registry::exists( $concept ) ? $concept : '';
    }

    /** @param array $results @param \Bricks\Query $query */
    public static function run_query( $results, $query ) {
        $concept = self::concept_for_object_type( (string) ( $query->object_type ?? '' ) );
        if ( $concept === '' ) {
            return $results;
        }
        return MMI_Product_Content_Registry::get_items( self::current_product_id(), $concept );
    }

    /** Rows aren't posts — keep post-scoped tags inside the loop resolving to the product. */
    public static function loop_object_id( $object_id, $object, $query_id ) {
        if ( self::concept_for_object_type( (string) \Bricks\Query::get_query_object_type( $query_id ) ) === '' ) {
            return $object_id;
        }
        return self::current_product_id();
    }

    public static function loop_object_type( $object_type, $object, $query_id ) {
        return self::concept_for_object_type( (string) \Bricks\Query::get_query_object_type( $query_id ) ) === '' ? $object_type : 'mmi_spec';
    }

    /**
     * The product being rendered. Inside a spec loop get_the_ID() is still
     * the product (spec loops never call setup_postdata()), and inside a
     * product query loop it's that loop's product.
     */
    private static function current_product_id(): int {
        return (int) get_the_ID();
    }

    /* ── Dynamic tags ─────────────────────────────────────────────────────── */

    public static function register_tags( array $tags ): array {
        foreach ( self::ITEM_TAGS as $name => [ , $label ] ) {
            $tags[] = [ 'name' => '{' . $name . '}', 'label' => $label, 'group' => self::TAG_GROUP ];
        }
        foreach ( MMI_Product_Content_Registry::CONCEPTS as $concept => $def ) {
            $tags[] = [ 'name' => '{' . self::QUERY_PREFIX . $concept . '}', 'label' => $def['label'], 'group' => self::TAG_GROUP ];
            if ( $def['type'] === 'list' ) {
                $tags[] = [ 'name' => '{' . self::QUERY_PREFIX . $concept . '_count}', 'label' => $def['label'] . ' (count)', 'group' => self::TAG_GROUP ];
            }
        }
        return $tags;
    }

    /**
     * Value for one of this integration's tags, or null when the tag isn't ours.
     *
     * @param \WP_Post|null $post
     */
    private static function tag_value( string $tag, $post ): ?string {
        if ( strpos( $tag, self::QUERY_PREFIX ) !== 0 ) {
            return null;
        }

        if ( isset( self::ITEM_TAGS[ $tag ] ) ) {
            $row = \Bricks\Query::is_any_looping() ? \Bricks\Query::get_loop_object() : null;
            return is_array( $row ) ? (string) ( $row[ self::ITEM_TAGS[ $tag ][0] ] ?? '' ) : '';
        }

        $product_id = $post instanceof \WP_Post ? (int) $post->ID : self::current_product_id();
        $concept    = substr( $tag, strlen( self::QUERY_PREFIX ) );
        if ( substr( $concept, -6 ) === '_count' && MMI_Product_Content_Registry::exists( substr( $concept, 0, -6 ) ) ) {
            $count = MMI_Product_Content_Registry::count( $product_id, substr( $concept, 0, -6 ) );
            // Empty string (not "0") so Bricks' "is not empty" conditions work on counts too.
            return $count > 0 ? (string) $count : '';
        }
        return MMI_Product_Content_Registry::exists( $concept )
            ? MMI_Product_Content_Registry::get_text( $product_id, $concept )
            : null;
    }

    public static function render_tag( $tag, $post, $context = 'text' ) {
        if ( ! is_string( $tag ) ) {
            return $tag;
        }
        $value = self::tag_value( trim( $tag, '{} ' ), $post );
        return $value === null ? $tag : self::escape( $value, (string) $context );
    }

    private static function escape( string $value, string $context ): string {
        // URL-type contexts get the raw value (a bare YouTube ID must survive),
        // minus any non-http(s) scheme — spec values come from supplier feeds,
        // so a "javascript:" value must never reach an href/src.
        return in_array( $context, self::RAW_CONTEXTS, true )
            ? wp_kses_bad_protocol( $value, [ 'http', 'https' ] )
            : esc_html( $value );
    }

    public static function render_content( $content, $post, $context = 'text' ) {
        if ( ! is_string( $content ) || strpos( $content, '{' . self::QUERY_PREFIX ) === false ) {
            return $content;
        }
        return preg_replace_callback(
            '/\{(' . self::QUERY_PREFIX . '[a-z0-9_]+)\}/',
            static function ( $m ) use ( $post, $context ) {
                $value = self::tag_value( $m[1], $post );
                if ( $value === null ) {
                    return $m[0];
                }
                // bricks/frontend/render_data passes an area name ("content"), not a
                // context — anything that isn't a URL-type context is escaped as text.
                return self::escape( $value, (string) $context );
            },
            $content
        );
    }
}
