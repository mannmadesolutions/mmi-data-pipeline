<?php
/**
 * Field Locks — per-product protection against import overwrites
 *
 * A locked field is never written by any import to an EXISTING product: not
 * by the Import Profile worker (Product_Import_Worker), not by the dynamic
 * importer (Product_CRUD_Manager / Taxonomy_Mapping_Handler), and Import
 * Preview never counts it as a pending change. New products are unaffected —
 * a lock only exists on a product that already exists.
 *
 * Typical use: a merchandiser (or another plugin) rewrites a product's title
 * or description and wants it to survive every future feed refresh, while
 * price/stock keep updating normally.
 *
 * Storage: one postmeta row, META_KEY, holding a list of canonical field keys
 * — the same keys MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS uses
 * (post_title, post_content, _regular_price, product_brand, …). Stored as a
 * PHP array so it rides WP's meta cache: wc_get_product() / get_post_meta()
 * already prime it, so checking a lock inside an import loop costs no query.
 *
 * Public API (for sibling plugins):
 *   MMI_Pipeline_Field_Locks::lock( $product_id, 'post_title' );
 *   MMI_Pipeline_Field_Locks::unlock( $product_id, 'post_title' );
 *   MMI_Pipeline_Field_Locks::is_locked( $product_id, 'post_title' );
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Field_Locks {

    const META_KEY = '_mmi_locked_fields';

    const NONCE_ACTION = 'mmi_field_locks_save_';
    const NONCE_FIELD  = 'mmi_field_locks_nonce';

    /** DEFAULTS groups never offered for locking: bookkeeping written by the importer itself. */
    const EXCLUDED_GROUPS = [ 'meta' ];

    /** Identity/derived fields — locking them would break matching or WC's own price logic. */
    const EXCLUDED_FIELDS = [ '_sku', '_price' ];

    /** Fields shown outside the "More fields" disclosure — the ones people actually hand-edit. */
    const PRIMARY_FIELDS = [ 'post_title', 'post_content', 'post_excerpt' ];

    /**
     * Alternate field names the dynamic importer's mapped-data arrays use
     * (Product_CRUD_Manager::apply_fields_to_product()'s switch) → the
     * canonical DEFAULTS key a lock is stored under.
     */
    const ALIASES = [
        'name'              => 'post_title',
        'description'       => 'post_content',
        'short_description' => 'post_excerpt',
        'regular_price'     => '_regular_price',
        'sale_price'        => '_sale_price',
        'stock_quantity'    => '_stock',
        'stock_status'      => '_stock_status',
        'weight'            => '_weight',
        'length'            => '_length',
        'width'             => '_width',
        'height'            => '_height',
        'categories'        => 'product_cat',
        'product_cat_ids'   => 'product_cat',
        'tags'              => 'product_tag',
        'product_tag_ids'   => 'product_tag',
        'images'            => '_product_image_url',
    ];

    const BULK_ACTIONS = [
        'mmi_lock_title'       => [ 'label' => 'Lock title from imports',       'fields' => [ 'post_title' ] ],
        'mmi_lock_description' => [ 'label' => 'Lock description from imports', 'fields' => [ 'post_content', 'post_excerpt' ] ],
        'mmi_unlock_all'       => [ 'label' => 'Unlock all import fields',      'fields' => [] ],
    ];

    public static function init(): void {
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post_product', [ __CLASS__, 'save_metabox' ], 10, 1 );
        add_filter( 'bulk_actions-edit-product', [ __CLASS__, 'register_bulk_actions' ] );
        add_filter( 'handle_bulk_actions-edit-product', [ __CLASS__, 'handle_bulk_action' ], 10, 3 );
        add_action( 'admin_notices', [ __CLASS__, 'bulk_action_notice' ] );
    }

    /* ── Core API ─────────────────────────────────────────────────────────── */

    /**
     * Canonical lock key for any field name an importer might use:
     * 'tax:{slug}' dynamic taxonomy rows → the bare taxonomy slug, and the
     * dynamic importer's short names ('name', 'regular_price', …) → DEFAULTS keys.
     */
    public static function canonical( string $field ): string {
        if ( strpos( $field, 'tax:' ) === 0 ) {
            $field = substr( $field, 4 );
        }
        return self::ALIASES[ $field ] ?? $field;
    }

    /** @return string[] Canonical locked field keys for this product. */
    public static function get( int $product_id ): array {
        if ( $product_id <= 0 ) {
            return [];
        }
        $stored = get_post_meta( $product_id, self::META_KEY, true );
        return is_array( $stored ) ? array_values( array_filter( $stored, 'is_string' ) ) : [];
    }

    /** Membership test against an already-fetched list — use inside loops. */
    public static function in_list( string $field, array $locked ): bool {
        return $locked && in_array( self::canonical( $field ), $locked, true );
    }

    public static function is_locked( int $product_id, string $field ): bool {
        return self::in_list( $field, self::get( $product_id ) );
    }

    /** Replace a product's whole lock list. Unknown keys are dropped. */
    public static function set( int $product_id, array $fields ): void {
        $lockable = self::lockable_fields();
        $clean    = [];
        foreach ( $fields as $field ) {
            $key = self::canonical( (string) $field );
            if ( isset( $lockable[ $key ] ) ) {
                $clean[ $key ] = true;
            }
        }

        if ( ! $clean ) {
            delete_post_meta( $product_id, self::META_KEY );
            return;
        }
        update_post_meta( $product_id, self::META_KEY, array_keys( $clean ) );
    }

    public static function lock( int $product_id, string $field ): void {
        self::set( $product_id, array_merge( self::get( $product_id ), [ $field ] ) );
    }

    public static function unlock( int $product_id, string $field ): void {
        $key = self::canonical( $field );
        self::set( $product_id, array_filter( self::get( $product_id ), static fn( $f ) => $f !== $key ) );
    }

    /**
     * Drop locked keys from a dynamic-importer mapped-data array
     * (Product_CRUD_Manager's field names, aliases included).
     */
    public static function filter_data( array $data, array $locked ): array {
        if ( ! $locked ) {
            return $data;
        }
        foreach ( array_keys( $data ) as $field ) {
            if ( self::in_list( (string) $field, $locked ) ) {
                unset( $data[ $field ] );
            }
        }
        return $data;
    }

    /**
     * Lockable fields → label, grouped by DEFAULTS order. Derived from
     * MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS so a new mapping field is
     * lockable the moment it exists, with no second list to maintain.
     *
     * @return array<string,string>
     */
    public static function lockable_fields(): array {
        static $fields = null;
        if ( $fields !== null ) {
            return $fields;
        }
        $fields = [];
        foreach ( MMI_Pipeline_Field_Mapping_Defaults::DEFAULTS as $key => $def ) {
            if ( in_array( $key, self::EXCLUDED_FIELDS, true ) || in_array( $def['group'] ?? '', self::EXCLUDED_GROUPS, true ) ) {
                continue;
            }
            $fields[ $key ] = (string) ( $def['label'] ?? $key );
        }
        return $fields;
    }

    /* ── Product edit screen ──────────────────────────────────────────────── */

    public static function register_metabox(): void {
        add_meta_box(
            'mmi_field_locks',
            __( 'Import Field Locks', 'mmi-data-pipeline' ),
            [ __CLASS__, 'render_metabox' ],
            'product',
            'side',
            'default'
        );
    }

    public static function render_metabox( \WP_Post $post ): void {
        $locked   = self::get( $post->ID );
        $lockable = self::lockable_fields();
        $primary  = array_intersect_key( $lockable, array_flip( self::PRIMARY_FIELDS ) );
        $more     = array_diff_key( $lockable, $primary );
        $more_locked = count( array_intersect( array_keys( $more ), $locked ) );

        wp_nonce_field( self::NONCE_ACTION . $post->ID, self::NONCE_FIELD );
        ?>
        <p class="mmi-metabox-description">
            <?php esc_html_e( 'Checked fields keep their current value: data imports never overwrite them on this product.', 'mmi-data-pipeline' ); ?>
        </p>
        <?php self::render_checkboxes( $primary, $locked ); ?>
        <details <?php echo $more_locked ? 'open' : ''; ?>>
            <summary>
                <?php
                echo esc_html(
                    $more_locked
                        /* translators: %d: number of locked fields */
                        ? sprintf( _n( 'More fields (%d locked)', 'More fields (%d locked)', $more_locked, 'mmi-data-pipeline' ), $more_locked )
                        : __( 'More fields', 'mmi-data-pipeline' )
                );
                ?>
            </summary>
            <?php self::render_checkboxes( $more, $locked ); ?>
        </details>
        <?php
    }

    private static function render_checkboxes( array $fields, array $locked ): void {
        foreach ( $fields as $key => $label ) {
            $id = 'mmi_field_lock_' . sanitize_key( $key );
            ?>
            <p>
                <label for="<?php echo esc_attr( $id ); ?>">
                    <input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="mmi_field_locks[]"
                           value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $locked, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </label>
            </p>
            <?php
        }
    }

    public static function save_metabox( int $post_id ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION . $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $raw = isset( $_POST['mmi_field_locks'] ) && is_array( $_POST['mmi_field_locks'] )
            ? array_map( 'sanitize_text_field', wp_unslash( $_POST['mmi_field_locks'] ) )
            : [];
        self::set( $post_id, $raw );
    }

    /* ── Products list bulk actions ───────────────────────────────────────── */

    public static function register_bulk_actions( array $actions ): array {
        foreach ( self::BULK_ACTIONS as $key => $def ) {
            $actions[ $key ] = __( $def['label'], 'mmi-data-pipeline' ); // phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText
        }
        return $actions;
    }

    public static function handle_bulk_action( string $redirect_to, string $action, array $post_ids ): string {
        if ( ! isset( self::BULK_ACTIONS[ $action ] ) ) {
            return $redirect_to;
        }
        // WP core already verified the bulk-edit nonce before firing this filter.
        $done = 0;
        foreach ( array_map( 'intval', $post_ids ) as $post_id ) {
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                continue;
            }
            $fields = self::BULK_ACTIONS[ $action ]['fields'];
            self::set( $post_id, $fields ? array_merge( self::get( $post_id ), $fields ) : [] );
            $done++;
        }

        MMI_Logger::info( 'Field locks bulk action applied', [ 'action' => $action, 'products' => $done ], 'general', 'MMI_Pipeline_Field_Locks' );
        if ( function_exists( 'mmi_data_pipeline_audit' ) ) {
            mmi_data_pipeline_audit( 'field_locks.bulk', [
                'object_type' => 'product',
                'outcome'     => 'success',
                'details'     => [ 'action' => $action, 'products' => $done ],
            ] );
        }

        return add_query_arg( [ 'mmi_field_locks_action' => $action, 'mmi_field_locks_count' => $done ], $redirect_to );
    }

    public static function bulk_action_notice(): void {
        // Display-only echo of our own redirect args — nothing is written here.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $action = sanitize_key( $_GET['mmi_field_locks_action'] ?? '' );
        $count  = absint( $_GET['mmi_field_locks_count'] ?? 0 );
        // phpcs:enable
        if ( ! isset( self::BULK_ACTIONS[ $action ] ) ) {
            return;
        }
        $message = $action === 'mmi_unlock_all'
            /* translators: %d: number of products */
            ? sprintf( _n( 'Unlocked all import fields on %d product.', 'Unlocked all import fields on %d products.', $count, 'mmi-data-pipeline' ), $count )
            /* translators: %d: number of products */
            : sprintf( _n( 'Locked on %d product: future imports will not overwrite it.', 'Locked on %d products: future imports will not overwrite them.', $count, 'mmi-data-pipeline' ), $count );
        printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( $message ) );
    }
}
