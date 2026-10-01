<?php
/**
 * Data Type Registry
 *
 * Central lookup for every MMI_Data_Type_Handler implementation. Phase 1
 * registers only the Product handler; later phases register Order,
 * Customer, Coupon, generic Post/Page/CPT, generic Taxonomy, Comment, and
 * User handlers here without any other core file needing to change.
 *
 * @package MannMade\DataPipeline\DataTypes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Data_Type_Registry {

    /** @var array<string, MMI_Data_Type_Handler> */
    private static array $handlers = [];

    /** @var bool */
    private static bool $booted = false;

    /**
     * Register the built-in handlers and fire an extension hook for
     * third-party/dynamic (CPT, taxonomy) registration. Hooked on 'init'
     * (priority 30) so third-party CPTs/taxonomies are already registered
     * by the time this runs — not on 'plugins_loaded'.
     */
    public static function boot(): void {
        if ( self::$booted ) {
            return;
        }
        self::$booted = true;

        if ( class_exists( 'MMI_Product_Data_Type' ) ) {
            self::register( new MMI_Product_Data_Type() );
        }
        if ( class_exists( 'MMI_Order_Data_Type' ) ) {
            self::register( new MMI_Order_Data_Type() );
        }
        if ( class_exists( 'MMI_Customer_Data_Type' ) ) {
            self::register( new MMI_Customer_Data_Type() );
        }
        if ( class_exists( 'MMI_Coupon_Data_Type' ) ) {
            self::register( new MMI_Coupon_Data_Type() );
        }
        if ( class_exists( 'MMI_Comment_Data_Type' ) ) {
            self::register( new MMI_Comment_Data_Type() );
        }
        if ( class_exists( 'MMI_User_Data_Type' ) ) {
            self::register( new MMI_User_Data_Type() );
        }

        self::register_post_types_and_taxonomies();

        /**
         * Fires after built-in handlers are registered. Later phases (and any
         * third-party MMI plugin) hook here to register additional handlers,
         * including dynamic per-CPT/per-taxonomy instances.
         *
         * @param void No args — implementations call MMI_Data_Type_Registry::register() directly.
         */
        do_action( 'mmi_pipeline_register_data_type_handlers' );
    }

    /**
     * Dynamically register one MMI_WP_Post_Type_Data_Type per public post
     * type (excluding types with a dedicated handler above) and one
     * MMI_WP_Taxonomy_Data_Type per public taxonomy — the "collapse N types
     * into 1 parameterized class" part of the architecture. Runs on 'init'
     * (via boot()'s own hook priority 30), after third-party plugins
     * (JetEngine, ACF, etc.) have registered their own CPTs/taxonomies.
     */
    private static function register_post_types_and_taxonomies(): void {
        if ( class_exists( 'MMI_WP_Post_Type_Data_Type' ) ) {
            $excluded_post_types = [ 'product', 'shop_coupon', 'shop_order', 'product_variation', 'attachment' ];
            foreach ( get_post_types( [ 'public' => true, 'show_ui' => true ], 'objects' ) as $pt ) {
                if ( in_array( $pt->name, $excluded_post_types, true ) ) {
                    continue;
                }
                self::register( new MMI_WP_Post_Type_Data_Type( $pt->name ) );
            }
        }

        if ( class_exists( 'MMI_WP_Taxonomy_Data_Type' ) ) {
            foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $tax ) {
                self::register( new MMI_WP_Taxonomy_Data_Type( $tax->name ) );
            }
        }
    }

    public static function register( MMI_Data_Type_Handler $handler ): void {
        self::$handlers[ $handler->get_type_key() ] = $handler;
    }

    public static function get( string $type_key ): ?MMI_Data_Type_Handler {
        if ( ! self::$booted ) {
            self::boot();
        }
        return self::$handlers[ $type_key ] ?? null;
    }

    /**
     * @return array<string, MMI_Data_Type_Handler>
     */
    public static function all(): array {
        if ( ! self::$booted ) {
            self::boot();
        }
        return self::$handlers;
    }

    /**
     * Flat [type_key => label] list for the data-type picker dropdown.
     *
     * @return array<string, string>
     */
    public static function get_choices_for_ui(): array {
        $choices = [];
        foreach ( self::all() as $key => $handler ) {
            $choices[ $key ] = $handler->get_label();
        }
        return $choices;
    }
}
