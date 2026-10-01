<?php
/**
 * Rule Builder View
 *
 * Data Pipeline's side of the suite-wide condition builder
 * (MMI_Condition_Builder, shared library): the Source groups every builder
 * here offers, the "Load saved conditions" contributions (Catalog
 * Maintenance rules + Field Mapping conditions in every profile), and the
 * action picker + action settings Custom Rules and Product Workbench share
 * (assets/js/rule-builder.js). Condition markup/behavior/styles live in the
 * shared library so every builder in the suite looks and works the same.
 *
 * @package MannMade\DataPipeline
 */

namespace MannMade\DataPipeline;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Rule_Builder_View {

    /** Field Mapping's "this field's own value" source (see Stock_Override_Resolver). */
    const FIELD_VALUE_FIELD = 'value';

    /**
     * Condition Source list: supplier feeds first, then the WP/WC sources.
     * Prefers mmi_reverb_get_enabled_sources() when available (same
     * derivation Catalog Maintenance has always used).
     *
     * @param  array $configured_suppliers supplier_id => ['supplier_name' => ...].
     * @return list<array{id:string,name:string}>
     */
    public static function sources( array $configured_suppliers ): array {
        if ( function_exists( 'mmi_reverb_get_enabled_sources' ) ) {
            return mmi_reverb_get_enabled_sources();
        }
        $sources = [];
        foreach ( $configured_suppliers as $sid => $sinfo ) {
            $sources[] = [ 'id' => $sid, 'name' => $sinfo['supplier_name'] ];
        }
        return $sources;
    }

    /**
     * Action <option>s from the single registry the save/apply handlers
     * validate against (Custom_Rule_Actions::ACTION_GROUPS).
     *
     * @param string $selected
     * @param bool   $one_off  True for Product Workbench: leaves out the
     *                         Stock group. Force In/Out of Stock only holds
     *                         as a standing Custom Rule — a one-off write
     *                         would be undone by the next feed sync.
     */
    public static function action_options( string $selected = '', bool $one_off = false ): void {
        foreach ( Custom_Rule_Actions::ACTION_GROUPS as $group_label => $actions ) {
            if ( $one_off && 'Stock' === $group_label ) {
                continue;
            }
            ?>
            <optgroup label="<?php echo esc_attr( $group_label ); ?>">
                <?php foreach ( $actions as $key => $def ) : ?>
                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( $def['label'] ); ?></option>
                <?php endforeach; ?>
            </optgroup>
            <?php
        }
    }

    /**
     * Product taxonomies for the Set Taxonomy Term action — the same
     * show_ui-filtered list the wp_taxonomy condition source offers.
     *
     * @return list<array{value:string,label:string}>
     */
    public static function action_taxonomies(): array {
        $out = [];
        foreach ( get_object_taxonomies( 'product', 'objects' ) as $tax ) {
            if ( $tax->show_ui ) {
                $out[] = [ 'value' => $tax->name, 'label' => $tax->label ];
            }
        }
        usort( $out, static fn( $a, $b ) => strcasecmp( $a['label'], $b['label'] ) );
        return $out;
    }

    /**
     * Action-specific settings. Only the group whose data-action-types lists
     * the selected action is visible (rule-builder.js).
     */
    public static function action_params( array $params = [] ): void {
        static $taxonomies = null;
        if ( null === $taxonomies ) {
            $taxonomies = self::action_taxonomies();
        }
        $tax    = (string) ( $params['taxonomy'] ?? '' );
        $term   = (int) ( $params['term_id'] ?? 0 );
        $mode   = (string) ( $params['mode'] ?? 'replace' );
        ?>
        <div class="mmi-crp-group" data-action-types="set_taxonomy_term">
            <select class="mmi-crp-taxonomy">
                <option value="">Taxonomy&hellip;</option>
                <?php foreach ( $taxonomies as $t ) : ?>
                    <option value="<?php echo esc_attr( $t['value'] ); ?>" <?php selected( $tax, $t['value'] ); ?>><?php echo esc_html( $t['label'] ); ?></option>
                <?php endforeach; ?>
            </select>
            <select class="mmi-crp-term" <?php disabled( $tax, '' ); ?>>
                <option value="">Term&hellip;</option>
                <?php
                if ( $term > 0 ) {
                    $pre = get_term( $term );
                    if ( $pre && ! is_wp_error( $pre ) ) {
                        echo '<option value="' . (int) $term . '" selected>' . esc_html( $pre->name ) . '</option>';
                    }
                }
                ?>
            </select>
            <select class="mmi-crp-tax-mode">
                <option value="replace" <?php selected( $mode, 'replace' ); ?>>Replace</option>
                <option value="add"     <?php selected( $mode, 'add' ); ?>>Add</option>
                <option value="remove"  <?php selected( $mode, 'remove' ); ?>>Remove</option>
            </select>
            <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                  title="Replace clears every existing term in this taxonomy on a matched product before setting the one chosen here. Add sets this term alongside whatever the product already has. Remove takes this term off, if present, and leaves everything else untouched."></span>
        </div>
        <div class="mmi-crp-group" data-action-types="set_regular_price,set_sale_price">
            <label>Price</label>
            <input type="text" class="mmi-crp-price" value="<?php echo esc_attr( $params['value'] ?? '' ); ?>" placeholder="e.g. 49.99">
            <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                  title="A plain number, no currency symbol (e.g. 49.99)."></span>
        </div>
        <div class="mmi-crp-group" data-action-types="set_sale_dates">
            <label>From</label>
            <input type="date" class="mmi-crp-date-from" value="<?php echo esc_attr( $params['date_from'] ?? '' ); ?>">
            <label>To</label>
            <input type="date" class="mmi-crp-date-to" value="<?php echo esc_attr( $params['date_to'] ?? '' ); ?>">
            <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                  title="Only sets the date window a sale price is active. Pair this with a separate Set Sale Price rule (or an existing sale price already on the product) — this action alone doesn't set a price."></span>
        </div>
        <div class="mmi-crp-group" data-action-types="copy_source_field_to_meta">
            <label>Source Field</label>
            <input type="text" class="mmi-crp-source-field" value="<?php echo esc_attr( $params['source_field'] ?? '' ); ?>" placeholder="e.g. is_hardware">
            <label>Meta Key</label>
            <input type="text" class="mmi-crp-meta-key" value="<?php echo esc_attr( $params['meta_key'] ?? '' ); ?>" placeholder="e.g. is_hardware_flag">
            <span class="dashicons dashicons-editor-help mmi-info-icon" tabindex="0"
                  title="Copies a matched product's raw value for Source Field — the exact field name as it appears in the supplier's feed, e.g. is_hardware — into a WordPress custom field named Meta Key on that product. Useful for surfacing feed data that has no Field Mapping entry of its own."></span>
        </div>
        <?php
    }

    /* ── Condition builder (shared) ─────────────────────────────────────── */

    /**
     * Source groups for MMI_Condition_Builder: supplier feeds, then the
     * WP/WC sources. Field Mapping adds "This field's value" first.
     *
     * @param list<array{id:string,name:string}> $sources From sources().
     */
    public static function source_groups( array $sources, bool $with_field_value = false ): array {
        $groups = [];
        if ( $with_field_value ) {
            $groups[] = [
                'label'   => 'This Field',
                'options' => [ [
                    'id'     => Stock_Override_Resolver::FIELD_VALUE_SOURCE,
                    'name'   => 'This field’s value',
                    'origin' => 'source',
                    'fields' => [ [ 'value' => self::FIELD_VALUE_FIELD, 'label' => 'Mapped value (after transform)' ] ],
                ] ],
            ];
        }
        $groups[] = [
            'label'   => 'Supplier Data',
            'options' => array_map(
                static fn( $src ) => [ 'id' => (string) $src['id'], 'name' => (string) $src['name'], 'origin' => 'source' ],
                $sources
            ),
        ];
        $groups[] = \MMI_Condition_Builder::wp_source_group();
        return $groups;
    }

    /**
     * Supplier sources for the current site, computed once per request —
     * Field Mapping renders one builder per field.
     */
    public static function site_sources(): array {
        static $cache = null;
        if ( null === $cache ) {
            $suppliers = class_exists( 'MMI_Pipeline_Admin' ) ? \MMI_Pipeline_Admin::get_configured_suppliers() : [];
            $cache     = self::sources( (array) $suppliers );
        }
        return $cache;
    }

    /** One condition row (kept for callers that render rows directly). */
    public static function condition_row( array $sources, array $cond = [] ): void {
        \MMI_Condition_Builder::condition_row( self::source_groups( $sources ), $cond );
    }

    /**
     * The retired Field Mapping builder saved {operator, compare, logic}
     * against the field's own value. Shown in the shared builder as "This
     * field's value" conditions; any OR in the old chain reads as "any".
     *
     * @return array{conditions: list<array>, match_logic: string}
     */
    public static function field_conditions_for_builder( array $mapping ): array {
        $conds = is_array( $mapping['conditions'] ?? null ) ? $mapping['conditions'] : [];
        $logic = 'any' === ( $mapping['condition_match_logic'] ?? 'all' ) ? 'any' : 'all';
        if ( ! $conds || Importers\Condition_Evaluator::is_builder_shape( $conds ) ) {
            return [ 'conditions' => $conds, 'match_logic' => $logic ];
        }
        $out = [];
        foreach ( $conds as $i => $c ) {
            if ( ! is_array( $c ) ) {
                continue;
            }
            $out[] = [
                'source'   => Stock_Override_Resolver::FIELD_VALUE_SOURCE,
                'field'    => self::FIELD_VALUE_FIELD,
                'operator' => (string) ( $c['operator'] ?? 'is_not_empty' ),
                'value'    => (string) ( $c['compare'] ?? '' ),
                'case_sensitive' => true, // the old evaluator compared case-sensitively
            ];
            if ( $i < count( $conds ) - 1 && 'OR' === strtoupper( (string) ( $c['logic'] ?? 'AND' ) ) ) {
                $logic = 'any';
            }
        }
        return [ 'conditions' => $out, 'match_logic' => $logic ];
    }

    /**
     * 'mmi_condition_library' contribution: every Custom Rule's conditions
     * and every import profile's per-field conditions, so any builder in the
     * suite can reuse them.
     */
    public static function library_sets( array $sets ): array {
        if ( ! class_exists( 'MMI_DB' ) ) {
            return $sets;
        }

        $rules = \MMI_DB::get_setting( 'mmi_stock_override_rules', [] );
        foreach ( is_array( $rules ) ? Stock_Override_Resolver::normalize_rules( $rules ) : [] as $i => $rule ) {
            if ( empty( $rule['conditions'] ) ) {
                continue;
            }
            $name   = trim( (string) ( $rule['name'] ?? '' ) );
            $action = Custom_Rule_Actions::label_for( (string) ( $rule['action'] ?? '' ) );
            $sets[] = [
                'id'          => 'cm:' . $i,
                'group'       => 'Catalog Maintenance',
                'label'       => '' !== $name ? $name : ( 'Custom Rule ' . ( $i + 1 ) . ( $action ? ' — ' . $action : '' ) ),
                'match_logic' => $rule['match_logic'] ?? 'all',
                'conditions'  => $rule['conditions'],
            ];
        }

        foreach ( \MMI_DB::get_profiles() as $profile_id => $profile ) {
            foreach ( \MMI_DB::get_field_mappings( (string) $profile_id ) as $field => $mapping ) {
                if ( ! is_array( $mapping ) || empty( $mapping['conditions'] ) ) {
                    continue;
                }
                $shown  = self::field_conditions_for_builder( $mapping );
                $sets[] = [
                    'id'          => 'fm:' . $profile_id . ':' . $field,
                    'group'       => 'Field Mapping — ' . ( $profile['name'] ?? $profile_id ),
                    'label'       => (string) $field,
                    'match_logic' => $shown['match_logic'],
                    'conditions'  => $shown['conditions'],
                ];
            }
        }

        return $sets;
    }
}

add_filter( 'mmi_condition_library', [ Rule_Builder_View::class, 'library_sets' ] );
