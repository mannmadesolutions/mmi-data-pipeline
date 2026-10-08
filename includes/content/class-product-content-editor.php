<?php
/**
 * Product Specs editor — the product edit screen UI for the Content Registry
 *
 * Edits every storable registry concept (system requirements, features,
 * videos, specifications, manuals, licensing, platforms, disclaimer) in its canonical
 * `_mmi_*` key via MMI_Product_Content_Registry::store(), which owns the
 * storage shape. A hand edit to a field an import can also write is
 * auto-locked through Field Locks, so the next feed refresh can't silently
 * revert it.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Product_Content_Editor {

    const NONCE_ACTION = 'mmi_product_specs_save_';
    const NONCE_FIELD  = 'mmi_product_specs_nonce';
    const FIELD        = 'mmi_specs';

    /** Registry concepts edited as one-row-per-line textareas. */
    const LINE_CONCEPTS = [
        'features'     => 'Features',
        'top_features' => 'Top features (curated highlights)',
        'videos'       => 'YouTube videos (URL or video ID; the first is the main video)',
    ];

    const REQUIREMENT_CONCEPTS = [
        'requirements_mac'     => 'macOS',
        'requirements_windows' => 'Windows',
        'requirements_linux'   => 'Linux',
    ];

    /** Must cover every text field in MMI_Product_Content_Registry::REQUIREMENT_TEXT_FIELDS except the textarea, or saving drops data. */
    const REQUIREMENT_TEXT_INPUTS = [
        'version'    => 'OS version',
        'cpu'        => 'CPU',
        'notes'      => 'OS notes',
        'audio_card' => 'Audio interface',
        'ports'      => 'Ports',
    ];

    const FORMAT_CHOICES = [
        'vst-2'       => 'VST2',
        'vst-3'       => 'VST3',
        'au'          => 'AU',
        'aax'         => 'AAX',
        'rtas'        => 'RTAS',
        'stand-alone' => 'Standalone',
        'other'       => 'Other',
    ];

    const BIT_CHOICES       = [ '64_bit' => '64-bit', '32_bit' => '32-bit' ];
    const METHOD_CHOICES    = [ 'ilok' => 'iLok', 'computer' => 'Computer activation', 'cloud' => 'Cloud', 'usb' => 'USB dongle' ];
    const ILOK_CHOICES      = [ 'machine' => 'Machine', 'cloud' => 'Cloud', 'usb' => 'USB' ];
    const PLATFORM_CHOICES  = [ 'mac' => 'macOS', 'windows' => 'Windows', 'linux' => 'Linux' ];

    public static function init(): void {
        add_action( 'add_meta_boxes_product', [ __CLASS__, 'register_metabox' ] );
        add_action( 'save_post_product', [ __CLASS__, 'save' ], 10, 1 );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_style' ] );
    }

    public static function register_metabox(): void {
        add_meta_box(
            'mmi_product_specs',
            __( 'Product Specs', 'mmi-data-pipeline' ),
            [ __CLASS__, 'render' ],
            'product',
            'normal',
            'default'
        );
    }

    public static function enqueue_style( string $hook ): void {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || $screen->post_type !== 'product' ) {
            return;
        }
        $rel = 'assets/css/product-content-editor.css';
        if ( file_exists( MMI_PIPELINE_PATH . $rel ) ) {
            wp_enqueue_style( 'mmi-product-content-editor', MMI_PIPELINE_URL . $rel, [], (string) filemtime( MMI_PIPELINE_PATH . $rel ) );
        }
    }

    /* ── Render ───────────────────────────────────────────────────────────── */

    public static function render( \WP_Post $post ): void {
        $id = (int) $post->ID;
        $R  = 'MMI_Product_Content_Registry';
        wp_nonce_field( self::NONCE_ACTION . $id, self::NONCE_FIELD );
        ?>
        <div class="mmi-specs-editor">
            <p class="description">
                <?php esc_html_e( 'Shown on the product page. Fields an import can also write are locked automatically when you change them here (see Import Field Locks).', 'mmi-data-pipeline' ); ?>
            </p>

            <h4><?php esc_html_e( 'System requirements', 'mmi-data-pipeline' ); ?></h4>
            <div class="mmi-specs-editor__os-grid">
                <?php foreach ( self::REQUIREMENT_CONCEPTS as $concept => $os_label ) : ?>
                    <?php $req = (array) $R::canonical_value( $id, $concept ); ?>
                    <fieldset class="mmi-specs-editor__os">
                        <legend><?php echo esc_html( $os_label ); ?></legend>
                        <?php foreach ( self::REQUIREMENT_TEXT_INPUTS as $field => $label ) : ?>
                            <?php self::text_input( "{$concept}[{$field}]", $label, (string) ( $req[ $field ] ?? '' ) ); ?>
                        <?php endforeach; ?>
                        <div class="mmi-specs-editor__pair">
                            <?php self::number_input( "{$concept}[ram]", __( 'RAM (GB)', 'mmi-data-pipeline' ), $req['ram'] ?? '' ); ?>
                            <?php self::number_input( "{$concept}[disk]", __( 'Disk (GB)', 'mmi-data-pipeline' ), $req['disk'] ?? '' ); ?>
                        </div>
                        <?php self::checkboxes( "{$concept}[plugins]", __( 'Formats', 'mmi-data-pipeline' ), self::FORMAT_CHOICES, (array) ( $req['plugins'] ?? [] ) ); ?>
                        <?php self::checkboxes( "{$concept}[support]", __( 'Architecture', 'mmi-data-pipeline' ), self::BIT_CHOICES, (array) ( $req['support'] ?? [] ) ); ?>
                        <label class="mmi-specs-editor__check">
                            <input type="checkbox" name="<?php echo esc_attr( self::FIELD . "[{$concept}][internet_required]" ); ?>" value="1" <?php checked( ! empty( $req['internet_required'] ) ); ?>>
                            <?php esc_html_e( 'Internet connection required', 'mmi-data-pipeline' ); ?>
                        </label>
                        <?php self::textarea( "{$concept}[additional_requirements]", __( 'Other requirements (one per line)', 'mmi-data-pipeline' ), (string) ( $req['additional_requirements'] ?? '' ), 3 ); ?>
                    </fieldset>
                <?php endforeach; ?>
            </div>

            <?php $platforms = (array) $R::canonical_value( $id, 'platforms' ); ?>
            <?php self::checkboxes( 'platforms', __( 'Platforms', 'mmi-data-pipeline' ), self::PLATFORM_CHOICES, $platforms ); ?>

            <?php $lic = (array) $R::canonical_value( $id, 'licensing' ); ?>
            <div class="mmi-specs-editor__pair">
                <?php self::checkboxes( 'licensing[methods]', __( 'Authorization', 'mmi-data-pipeline' ), self::METHOD_CHOICES, (array) ( $lic['methods'] ?? [] ) ); ?>
                <?php self::checkboxes( 'licensing[ilok]', __( 'iLok types', 'mmi-data-pipeline' ), self::ILOK_CHOICES, (array) ( $lic['ilok'] ?? [] ) ); ?>
            </div>

            <?php foreach ( self::LINE_CONCEPTS as $concept => $label ) : ?>
                <?php self::textarea( $concept, $label, implode( "\n", (array) $R::canonical_value( $id, $concept ) ), 5 ); ?>
            <?php endforeach; ?>

            <?php
            $pairs = array_map(
                static fn( $p ) => $p['label'] === '' ? $p['value'] : "{$p['label']}: {$p['value']}",
                (array) $R::canonical_value( $id, 'specs' )
            );
            self::textarea( 'specs', __( 'Specifications (one per line, "Label: Value")', 'mmi-data-pipeline' ), implode( "\n", $pairs ), 5 );
            $manuals = array_map(
                static fn( $p ) => $p['label'] === '' ? $p['value'] : "{$p['label']}: {$p['value']}",
                (array) $R::canonical_value( $id, 'manuals' )
            );
            self::textarea( 'manuals', __( 'Manuals & documentation (one per line, "Label: URL" or just the URL)', 'mmi-data-pipeline' ), implode( "\n", $manuals ), 3 );
            self::textarea( 'disclaimer', __( 'Disclaimer (shown as a "Note:" banner)', 'mmi-data-pipeline' ), (string) $R::canonical_value( $id, 'disclaimer' ), 2 );
            ?>
        </div>
        <?php
    }

    private static function field_name( string $path ): string {
        // "requirements_mac[ram]" → "mmi_specs[requirements_mac][ram]"
        $first = strpos( $path, '[' );
        return $first === false ? self::FIELD . "[{$path}]" : self::FIELD . '[' . substr( $path, 0, $first ) . ']' . substr( $path, $first );
    }

    private static function field_id( string $path ): string {
        return 'mmi-specs-' . sanitize_key( str_replace( [ '[', ']' ], '-', $path ) );
    }

    private static function text_input( string $path, string $label, string $value ): void {
        ?>
        <label class="mmi-specs-editor__field" for="<?php echo esc_attr( self::field_id( $path ) ); ?>">
            <span><?php echo esc_html( $label ); ?></span>
            <input type="text" class="widefat" id="<?php echo esc_attr( self::field_id( $path ) ); ?>" name="<?php echo esc_attr( self::field_name( $path ) ); ?>" value="<?php echo esc_attr( $value ); ?>">
        </label>
        <?php
    }

    private static function number_input( string $path, string $label, $value ): void {
        ?>
        <label class="mmi-specs-editor__field" for="<?php echo esc_attr( self::field_id( $path ) ); ?>">
            <span><?php echo esc_html( $label ); ?></span>
            <input type="number" min="0" step="0.1" class="small-text" id="<?php echo esc_attr( self::field_id( $path ) ); ?>" name="<?php echo esc_attr( self::field_name( $path ) ); ?>" value="<?php echo esc_attr( (string) $value ); ?>">
        </label>
        <?php
    }

    private static function textarea( string $path, string $label, string $value, int $rows ): void {
        ?>
        <label class="mmi-specs-editor__field" for="<?php echo esc_attr( self::field_id( $path ) ); ?>">
            <span><?php echo esc_html( $label ); ?></span>
            <textarea class="widefat" rows="<?php echo (int) $rows; ?>" id="<?php echo esc_attr( self::field_id( $path ) ); ?>" name="<?php echo esc_attr( self::field_name( $path ) ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
        </label>
        <?php
    }

    private static function checkboxes( string $path, string $label, array $choices, array $checked ): void {
        $checked = array_map( 'strtolower', array_map( 'strval', $checked ) );
        ?>
        <fieldset class="mmi-specs-editor__checks">
            <legend><?php echo esc_html( $label ); ?></legend>
            <?php foreach ( $choices as $value => $text ) : ?>
                <label class="mmi-specs-editor__check">
                    <input type="checkbox" name="<?php echo esc_attr( self::field_name( $path ) ); ?>[]" value="<?php echo esc_attr( $value ); ?>" <?php checked( in_array( $value, $checked, true ) ); ?>>
                    <?php echo esc_html( $text ); ?>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <?php
    }

    /* ── Save ─────────────────────────────────────────────────────────────── */

    public static function save( int $post_id ): void {
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! isset( $_POST[ self::NONCE_FIELD ], $_POST[ self::FIELD ] ) || ! is_array( $_POST[ self::FIELD ] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( sanitize_key( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION . $post_id ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        // Values are sanitized per shape by MMI_Product_Content_Registry::store().
        $input  = wp_unslash( $_POST[ self::FIELD ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        $values = self::values_from_input( $input );

        $changed = [];
        foreach ( $values as $concept => $value ) {
            if ( ! MMI_Product_Content_Registry::differs_from_stored( $post_id, $concept, $value ) ) {
                continue; // Re-posted prefill: not an edit, so don't rewrite or lock.
            }
            if ( MMI_Product_Content_Registry::store( $post_id, $concept, $value ) ) {
                $changed[] = $concept;
            }
        }
        if ( ! $changed ) {
            return;
        }

        // Lock every changed field an import could also write, so a feed refresh can't revert the edit.
        if ( class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
            $lockable = MMI_Pipeline_Field_Locks::lockable_fields();
            foreach ( $changed as $concept ) {
                $key = MMI_Product_Content_Registry::canonical_key( $concept );
                if ( isset( $lockable[ $key ] ) ) {
                    MMI_Pipeline_Field_Locks::lock( $post_id, $key );
                }
            }
        }

        MMI_Logger::info( 'Product specs edited', [ 'product_id' => $post_id, 'concepts' => $changed ], 'general', 'MMI_Product_Content_Editor' );
    }

    /**
     * Form input → registry storage shapes. Public so it can be exercised
     * without a real form post.
     */
    public static function values_from_input( array $input ): array {
        $lines = static fn( $text ) => array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) $text ) ), 'strlen' ) );
        $values = [];

        foreach ( array_keys( self::REQUIREMENT_CONCEPTS ) as $concept ) {
            $values[ $concept ] = (array) ( $input[ $concept ] ?? [] );
        }
        foreach ( array_keys( self::LINE_CONCEPTS ) as $concept ) {
            $values[ $concept ] = $lines( $input[ $concept ] ?? '' );
        }

        $values['specs'] = array_map(
            static function ( $line ) {
                $pos = strpos( $line, ':' );
                return $pos === false
                    ? [ 'label' => '', 'value' => $line ]
                    : [ 'label' => trim( substr( $line, 0, $pos ) ), 'value' => trim( substr( $line, $pos + 1 ) ) ];
            },
            $lines( $input['specs'] ?? '' )
        );
        // "User manual: https://…" or a bare URL; the label is whatever comes before the URL.
        $values['manuals']    = array_map(
            static function ( $line ) {
                $pos = stripos( $line, 'http' );
                return $pos === false || $pos === 0
                    ? [ 'label' => '', 'value' => $line ]
                    : [ 'label' => trim( rtrim( trim( substr( $line, 0, $pos ) ), ':-–|' ) ), 'value' => trim( substr( $line, $pos ) ) ];
            },
            $lines( $input['manuals'] ?? '' )
        );
        $values['licensing']  = (array) ( $input['licensing'] ?? [] );
        $values['platforms']  = (array) ( $input['platforms'] ?? [] );
        $values['disclaimer'] = (string) ( $input['disclaimer'] ?? '' );

        return $values;
    }
}
