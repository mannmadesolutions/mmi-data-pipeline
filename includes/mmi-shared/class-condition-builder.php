<?php
/**
 * MMI_Condition_Builder — the suite-wide condition builder
 * (Source → Field → Operator → Value rows, All/Any match logic, Add
 * Condition, and "Load saved conditions" from every other builder).
 *
 * One markup contract, driven by js/shared/mmi-condition-builder.js and
 * styled by mmi-suite-common.css's "Condition builder" block, so every MMI
 * plugin that inserts a builder gets identical look and behavior. Built from
 * mmi-data-pipeline's Catalog Maintenance Custom Rules builder (the look the
 * owner picked), which Product Workbench and Field Mapping now also use.
 *
 * What stays with each consumer:
 *   - Sources. Passed to render() as groups; an option may carry a static
 *     field list ('fields') so it needs no AJAX (e.g. Field Mapping's
 *     "This field's value").
 *   - Cascade AJAX. The field list / known-values / term actions and their
 *     nonce come from window.mmiConditionBuilder (wp_localize_script on the
 *     'mmi-condition-builder' handle) or a per-builder 'endpoints' override.
 *   - Evaluation. Each consumer evaluates the saved {source, field,
 *     operator, value, case_sensitive} list itself.
 *
 * Saved-conditions library: every builder can pull in any condition set
 * saved anywhere in the suite. Consumers contribute their saved sets via the
 * 'mmi_condition_library' filter (see library()); the shared AJAX action
 * 'mmi_condition_library' (registered eagerly in bootstrap.php) serves them.
 *
 * @package MMI_Shared
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'MMI_Condition_Builder' ) ) :

final class MMI_Condition_Builder {

	/** WP/WC sources every builder understands (values live on the product). */
	const TAXONOMY_SOURCE = 'wp_taxonomy';
	const POSTMETA_SOURCE = 'wp_postmeta';

	/** Operators whose comparison is text-based, so Match case applies. */
	const CASE_OPERATORS = array( 'equals', 'not_equals', 'contains', 'not_contains', 'starts_with', 'ends_with', 'in_list', 'not_in_list' );

	const OPERATOR_LABELS = array(
		'equals'       => '= equals',
		'not_equals'   => '≠ not equals',
		'contains'     => 'contains',
		'not_contains' => 'doesn’t contain',
		'starts_with'  => 'starts with',
		'ends_with'    => 'ends with',
		'in_list'      => 'is any of',
		'not_in_list'  => 'is none of',
		'is_empty'     => 'is empty',
		'is_not_empty' => 'is not empty',
		'greater_than' => '> greater than',
		'less_than'    => '< less than',
		'length_less_than'    => 'is shorter than',
		'length_greater_than' => 'is longer than',
		'term_default_only'   => 'is only default term',
		'matches_pattern'     => 'looks like',
	);

	/** Operators that take no value. */
	const NO_VALUE_OPERATORS = array( 'is_empty', 'is_not_empty', 'term_default_only' );

	/**
	 * 'matches_pattern' values — mirrors the evaluating consumer's own list
	 * (mmi-data-pipeline's Stock_Override_Resolver::PATTERNS).
	 */
	const PATTERNS = array(
		'all_caps'        => 'ALL CAPS',
		'numeric_only'    => 'only numbers',
		'brand_not_first' => 'doesn’t start with its brand',
	);

	const LIBRARY_ACTION = 'mmi_condition_library';

	/**
	 * The standard WordPress / WooCommerce source group.
	 *
	 * @return array{label:string,options:list<array>}
	 */
	public static function wp_source_group(): array {
		return array(
			'label'   => 'WordPress / WooCommerce',
			'options' => array(
				array( 'id' => self::TAXONOMY_SOURCE, 'name' => 'Taxonomy', 'origin' => 'app' ),
				array( 'id' => self::POSTMETA_SOURCE, 'name' => 'Post Field / Meta', 'origin' => 'app' ),
			),
		);
	}

	/**
	 * Placeholder for a condition's second dropdown. Mirrors
	 * fieldPlaceholder() in mmi-condition-builder.js.
	 */
	public static function field_placeholder( string $source ): string {
		if ( '' === $source ) {
			return 'What to check…';
		}
		if ( self::TAXONOMY_SOURCE === $source ) {
			return 'Taxonomy…';
		}
		if ( self::POSTMETA_SOURCE === $source ) {
			return 'Field or meta key…';
		}
		return 'Feed field…';
	}

	/**
	 * Render one builder.
	 *
	 * @param array $args {
	 *     @type array       $sources     Source groups: list of {label, options: list of
	 *                                    {id, name, origin?: 'source'|'app', fields?: list of {value,label}}}.
	 *     @type array       $conditions  Saved conditions to render.
	 *     @type string|null $match_logic 'all'|'any' renders a match-logic select in the
	 *                                    header; null (default) leaves match logic to the consumer.
	 *     @type string      $label       Header caption (e.g. "Conditions"); '' for none.
	 *     @type string      $empty_hint  Shown while there are no conditions.
	 *     @type string      $context     Identifies this builder in the library (so its own
	 *                                    saved set can be marked "this one").
	 *     @type string      $actions_html Extra buttons after Add Condition (trusted HTML).
	 *     @type bool        $library     Show "Load saved conditions" (default true).
	 *     @type array       $endpoints   Per-builder AJAX action overrides {fields, values, terms, nonce}.
	 *     @type string      $class       Extra classes on the root.
	 *     @type array       $attrs       Extra attributes on the root (name => value).
	 *     @type array       $ids         Optional ids: {root, list, add, match_logic}.
	 * }
	 */
	public static function render( array $args ): void {
		$args = array_merge(
			array(
				'sources'      => array(),
				'conditions'   => array(),
				'match_logic'  => null,
				'label'        => '',
				'empty_hint'   => 'No conditions yet.',
				'context'      => '',
				'actions_html' => '',
				'library'      => true,
				'endpoints'    => array(),
				'class'        => '',
				'attrs'        => array(),
				'ids'          => array(),
			),
			$args
		);

		$conditions = array_values( array_filter( (array) $args['conditions'], 'is_array' ) );
		$ids        = (array) $args['ids'];
		$id_attr    = static function ( string $key ) use ( $ids ): string {
			return empty( $ids[ $key ] ) ? '' : ' id="' . esc_attr( $ids[ $key ] ) . '"';
		};

		$root_attrs = array(
			// Under "any", every condition is already or'd: the join toggles hide.
			'class'           => trim( 'mmi-cb ' . ( 'any' === $args['match_logic'] ? 'mmi-cb--any ' : '' ) . $args['class'] ),
			'data-cb-context' => (string) $args['context'],
		);
		if ( $args['library'] ) {
			$root_attrs['data-cb-library-nonce'] = wp_create_nonce( self::LIBRARY_ACTION );
		}
		if ( ! empty( $args['endpoints'] ) ) {
			$root_attrs['data-cb-endpoints'] = wp_json_encode( $args['endpoints'] );
		}
		foreach ( (array) $args['attrs'] as $name => $value ) {
			$root_attrs[ $name ] = (string) $value;
		}

		$has_head = '' !== $args['label'] || null !== $args['match_logic'];
		?>
		<div<?php echo $id_attr( 'root' ); ?><?php foreach ( $root_attrs as $name => $value ) { echo ' ' . esc_attr( $name ) . '="' . esc_attr( $value ) . '"'; } ?>>
			<?php if ( $has_head ) : ?>
				<div class="mmi-cb-head">
					<?php if ( '' !== $args['label'] ) : ?>
						<span class="mmi-cb-label"><?php echo esc_html( $args['label'] ); ?></span>
					<?php endif; ?>
					<?php if ( null !== $args['match_logic'] ) : ?>
						<select class="mmi-cb-match-logic"<?php echo $id_attr( 'match_logic' ); ?> aria-label="Match logic">
							<option value="all" <?php selected( $args['match_logic'], 'all' ); ?>>Match all conditions</option>
							<option value="any" <?php selected( $args['match_logic'], 'any' ); ?>>Match any condition</option>
						</select>
					<?php endif; ?>
				</div>
			<?php endif; ?>
			<div class="mmi-conditions-list"<?php echo $id_attr( 'list' ); ?>>
				<?php foreach ( $conditions as $cond ) {
					self::condition_row( $args['sources'], $cond );
				} ?>
			</div>
			<p class="mmi-cb-empty-hint<?php echo empty( $conditions ) ? '' : ' mmi-hidden'; ?>"><?php echo esc_html( $args['empty_hint'] ); ?></p>
			<div class="mmi-cb-actions">
				<button type="button" class="mmi-add-condition-btn"<?php echo $id_attr( 'add' ); ?>>
					<span class="dashicons dashicons-plus-alt2"></span> Add Condition
				</button>
				<?php if ( $args['library'] ) : ?>
					<button type="button" class="mmi-cb-btn-secondary mmi-cb-library-btn" aria-expanded="false"
							title="Copy conditions already saved elsewhere — another rule, another import profile's field, or any other MMI condition builder.">
						<span class="dashicons dashicons-download"></span> Load saved conditions
					</button>
				<?php endif; ?>
				<?php echo $args['actions_html']; // phpcs:ignore WordPress.Security.EscapeOutput -- trusted consumer markup. ?>
				<span class="mmi-cb-notice" role="status"></span>
			</div>
			<?php if ( $args['library'] ) : ?>
				<div class="mmi-cb-library mmi-hidden">
					<input type="search" class="mmi-cb-library-search" placeholder="Search saved conditions&hellip;" aria-label="Search saved conditions">
					<div class="mmi-cb-library-list"></div>
				</div>
			<?php endif; ?>
			<template class="mmi-cb-row-template"><?php self::condition_row( $args['sources'] ); ?></template>
		</div>
		<?php
	}

	/**
	 * Source <option>s, one <optgroup> per group. Each option carries its
	 * origin (Source Data vs WP/WC Data badge) and, optionally, a static
	 * field list the JS uses instead of the fields AJAX.
	 */
	public static function source_options( array $groups, string $selected = '' ): void {
		foreach ( $groups as $group ) {
			$options = (array) ( $group['options'] ?? array() );
			if ( ! $options ) {
				continue;
			}
			echo '<optgroup label="' . esc_attr( (string) ( $group['label'] ?? '' ) ) . '">';
			foreach ( $options as $opt ) {
				$id     = (string) ( $opt['id'] ?? '' );
				$origin = (string) ( $opt['origin'] ?? ( in_array( $id, array( self::TAXONOMY_SOURCE, self::POSTMETA_SOURCE ), true ) ? 'app' : 'source' ) );
				echo '<option value="' . esc_attr( $id ) . '" data-cb-origin="' . esc_attr( $origin ) . '"';
				if ( ! empty( $opt['fields'] ) ) {
					echo ' data-cb-fields="' . esc_attr( wp_json_encode( array_values( $opt['fields'] ) ) ) . '"';
				}
				echo selected( $selected, $id, false ) . '>' . esc_html( (string) ( $opt['name'] ?? $id ) ) . '</option>';
			}
			echo '</optgroup>';
		}
	}

	/**
	 * One condition row. With $cond, the row carries data-saved-* attributes
	 * the JS restores from; without it, it's the blank row the builder's
	 * template clones.
	 */
	public static function condition_row( array $source_groups, array $cond = array() ): void {
		$source   = (string) ( $cond['source'] ?? '' );
		$field    = (string) ( $cond['field'] ?? '' );
		$operator = (string) ( $cond['operator'] ?? 'equals' );
		$value    = (string) ( $cond['value'] ?? '' );
		$case     = ! empty( $cond['case_sensitive'] );
		$blank    = empty( $cond );
		$or       = ! empty( $cond['or'] );
		// Not .mmi-hidden (display:none !important): the JS toggles this
		// input with jQuery .show()/.hide(), which !important blocks.
		$val_hidden = in_array( $operator, self::NO_VALUE_OPERATORS, true ) ? ' mmi-cond-value--hidden' : '';
		$case_na    = in_array( $operator, self::CASE_OPERATORS, true ) ? '' : ' mmi-cond-case--na';
		?>
		<div class="mmi-condition-row<?php echo $or ? ' mmi-condition-row--or' : ''; ?>"<?php if ( ! $blank ) : ?>
			 data-saved-source="<?php echo esc_attr( $source ); ?>"
			 data-saved-field="<?php echo esc_attr( $field ); ?>"
			 data-saved-operator="<?php echo esc_attr( $operator ); ?>"
			 data-saved-value="<?php echo esc_attr( $value ); ?>"
			 data-saved-or="<?php echo $or ? '1' : '0'; ?>"<?php endif; ?>>
			<button type="button" class="mmi-cond-join" aria-pressed="<?php echo $or ? 'true' : 'false'; ?>"
					title="How this condition joins the one above. &quot;or&quot; groups them: A, or B, and C means (A or B) and C.">
				<?php echo $or ? 'or' : 'and'; ?>
			</button>
			<select class="mmi-cond-source" aria-label="Source">
				<option value="">Source&hellip;</option>
				<?php self::source_options( $source_groups, $source ); ?>
			</select>
			<span class="mmi-cond-origin-badge mmi-badge" data-origin="">
				<span class="mmi-cond-origin-label mmi-cond-origin-label--source">Source Data</span>
				<span class="mmi-cond-origin-label mmi-cond-origin-label--app">WP/WC Data</span>
			</span>
			<select class="mmi-cond-field mmi-cascade-field" aria-label="Field" <?php disabled( $source, '' ); ?>>
				<option value=""><?php echo esc_html( self::field_placeholder( $source ) ); ?></option>
				<?php if ( '' !== $field ) : ?>
					<option value="<?php echo esc_attr( $field ); ?>" selected><?php echo esc_html( $field ); ?></option>
				<?php endif; ?>
			</select>
			<select class="mmi-cond-operator" aria-label="Operator" <?php disabled( $field, '' ); ?>>
				<?php foreach ( self::OPERATOR_LABELS as $op => $label ) : ?>
					<option value="<?php echo esc_attr( $op ); ?>" <?php selected( $operator, $op ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label class="mmi-cond-case<?php echo esc_attr( $case_na ); ?>" title="Match case — off: &quot;bundle&quot; also finds &quot;Bundle&quot; and &quot;BUNDLE&quot;">
				<input type="checkbox" class="mmi-cond-case-input" <?php checked( $case ); ?>>Aa
			</label>
			<input type="text" class="mmi-cond-value<?php echo esc_attr( $val_hidden ); ?>" value="<?php echo esc_attr( $value ); ?>" placeholder="Value&hellip;" aria-label="Value" <?php disabled( $blank ); ?>>
			<select class="mmi-cond-value-select mmi-hidden" aria-label="Value"></select>
			<div class="mmi-cond-value-checklist mmi-hidden">
				<button type="button" class="mmi-cvc-trigger">
					<span class="mmi-cvc-label">Select values&hellip;</span>
					<span class="dashicons dashicons-arrow-down-alt2"></span>
				</button>
				<div class="mmi-cvc-panel mmi-hidden">
					<input type="text" class="mmi-cvc-search" placeholder="Search&hellip;">
					<div class="mmi-cvc-options"></div>
				</div>
			</div>
			<button type="button" class="mmi-remove-condition-btn" title="Remove condition">
				<span class="dashicons dashicons-remove"></span>
			</button>
		</div>
		<?php
	}

	/* ── Saved-conditions library ─────────────────────────────────────── */

	/**
	 * Every saved condition set in the suite, from the 'mmi_condition_library'
	 * filter. Each contributor appends entries shaped:
	 *   [ 'id' => unique string, 'group' => 'Catalog Maintenance',
	 *     'label' => 'Rule name', 'match_logic' => 'all'|'any',
	 *     'conditions' => list of {source, field, operator, value, case_sensitive?, or?},
	 *     'delete_action' => optional AJAX action that deletes this set; the
	 *       picker then offers Delete and posts {id, nonce} to it, using the
	 *       builder's cascade nonce ]
	 *
	 * @return list<array>
	 */
	public static function library(): array {
		$sets = apply_filters( 'mmi_condition_library', array() );
		$out  = array();
		foreach ( (array) $sets as $set ) {
			if ( ! is_array( $set ) || empty( $set['conditions'] ) || ! is_array( $set['conditions'] ) ) {
				continue;
			}
			$conditions = array();
			foreach ( $set['conditions'] as $cond ) {
				if ( ! is_array( $cond ) || '' === (string) ( $cond['field'] ?? '' ) ) {
					continue;
				}
				$clean = array(
					'source'   => (string) ( $cond['source'] ?? '' ),
					'field'    => (string) $cond['field'],
					'operator' => (string) ( $cond['operator'] ?? 'equals' ),
					'value'    => (string) ( $cond['value'] ?? '' ),
				);
				if ( ! empty( $cond['case_sensitive'] ) ) {
					$clean['case_sensitive'] = 1;
				}
				if ( ! empty( $cond['or'] ) ) {
					$clean['or'] = 1;
				}
				$conditions[] = $clean;
			}
			if ( ! $conditions ) {
				continue;
			}
			$out[] = array(
				'id'          => (string) ( $set['id'] ?? md5( wp_json_encode( $conditions ) ) ),
				'group'       => (string) ( $set['group'] ?? 'Other' ),
				'label'       => (string) ( $set['label'] ?? 'Untitled' ),
				'match_logic' => 'any' === ( $set['match_logic'] ?? 'all' ) ? 'any' : 'all',
				'conditions'  => $conditions,
				'deletable'   => ! empty( $set['delete_action'] ),
				'delete_action' => (string) ( $set['delete_action'] ?? '' ),
			);
		}
		return $out;
	}

	/** AJAX: the library, for the "Load saved conditions" picker. */
	public static function ajax_library(): void {
		check_ajax_referer( self::LIBRARY_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}
		wp_send_json_success( array( 'sets' => self::library() ) );
	}
}

endif;
