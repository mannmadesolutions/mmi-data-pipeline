<?php
/**
 * Pipeline Field Schema Resolver
 *
 * Generalizes "effective field mappings for a profile" beyond the
 * product/import case that MMI_Pipeline_Field_Mapping_Defaults::get_effective()
 * already handles. For data_type=product + direction=import this is a pure
 * pass-through to that existing method (zero behavior change). For every
 * other data_type/direction combination it pulls the field schema from the
 * registered MMI_Data_Type_Handler and merges saved field mappings on top.
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class MMI_Pipeline_Field_Schema_Resolver {

    /**
     * @param string $profile_id
     * @param string $data_type  e.g. 'product', 'order', 'cpt:book'.
     * @param string $direction  'import' or 'export'.
     * @return array Effective field mappings/schema.
     */
    public static function get_effective( string $profile_id, string $data_type, string $direction ): array {
        if ( $data_type === 'product' && $direction === 'import' ) {
            return MMI_Pipeline_Field_Mapping_Defaults::get_effective( $profile_id );
        }

        $handler = MMI_Data_Type_Registry::get( $data_type );
        if ( ! $handler ) {
            return [];
        }

        $saved = MMI_DB::get_field_mappings( $profile_id );
        return self::merge( $handler->get_field_schema(), is_array( $saved ) ? $saved : [] );
    }

    /**
     * Merge saved field mappings on top of a data type's schema defaults.
     *
     * Simpler than MMI_Pipeline_Field_Mapping_Defaults::merge() because the
     * schema here has no per-supplier 'source'/'enabled' sub-arrays to
     * deep-merge (that's an import-from-external-feed concept) — export
     * field config is flat: enabled/label/transform/output_column per field.
     *
     * @param array $schema Handler's get_field_schema() output.
     * @param array $saved  Saved overrides from MMI_DB::get_field_mappings().
     * @return array
     */
    public static function merge( array $schema, array $saved ): array {
        $effective = [];

        foreach ( $schema as $field => $defaults ) {
            $effective[ $field ] = array_merge(
                $defaults,
                [ 'enabled' => $defaults['enabled'] ?? true ],
                $saved[ $field ] ?? []
            );

            // A required field (the record's ID and title/name-equivalent —
            // see each handler's get_field_schema()) always exports, even if
            // a saved mapping has enabled:false — every export must carry
            // enough to identify which record each row is, regardless of
            // what the user toggled off in the Columns popover.
            if ( ! empty( $defaults['required'] ) ) {
                $effective[ $field ]['enabled'] = true;
            }
        }

        // Append any fully-custom fields the user added beyond the schema.
        foreach ( $saved as $field => $config ) {
            if ( ! isset( $effective[ $field ] ) ) {
                $effective[ $field ] = array_merge(
                    [ 'label' => $field, 'type' => 'string', 'group' => 'meta', 'required' => false, 'enabled' => true ],
                    $config
                );
            }
        }

        return $effective;
    }
}
