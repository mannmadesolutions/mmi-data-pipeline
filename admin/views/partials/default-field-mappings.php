<?php
/**
 * Default Field Mappings — Shared Data
 *
 * Sets $default_mappings to the effective (defaults + saved overrides) field
 * mappings for the Field Mapping UI panel. The actual default values and
 * merge logic live in MMI_Pipeline_Field_Mapping_Defaults so the importer
 * and pre-flight validator see the exact same effective mappings as this UI.
 *
 * Requires $field_mappings to already be set by the including view.
 *
 * @package MannMade\DataPipeline
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!isset($field_mappings) || !is_array($field_mappings)) {
    $field_mappings = [];
}

require_once MMI_PIPELINE_PATH . 'includes/class-pipeline-field-mapping-defaults.php';

$default_mappings = MMI_Pipeline_Field_Mapping_Defaults::merge( $field_mappings );
