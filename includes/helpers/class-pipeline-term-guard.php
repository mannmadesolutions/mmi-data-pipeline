<?php
/**
 * Keeps raw supplier values from becoming product categories or brands.
 *
 * Taxonomy Mapping decides which term a supplier value ("Software / DAW")
 * gets. The importer already honours that (MMI_Pipeline_Field_Resolver::
 * term_creation_allowed()), but any other code that calls wp_insert_term()
 * with a raw value bypassed it: on 2026-11-20 five empty categories named
 * exactly after mapped Xchange values appeared (#5800–5804) and nothing
 * recorded what made them. This guard sits on pre_insert_term, so it covers
 * every caller in every plugin, and it names the caller in the log.
 *
 * A term is refused only when all of these hold:
 *  - the taxonomy is decided by Taxonomy Mapping (TAXONOMY_MAPPING_ONLY);
 *  - its name is a value a supplier sends (a Taxonomy Mapping row's source
 *    value, or a value imports stored in _mmi_src_*);
 *  - no loaded import profile is set to "Create a new term";
 *  - a person didn't ask for it by name (allow(), or WordPress's own
 *    category screen).
 *
 * @package MannMade\DataPipeline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MMI_Pipeline_Term_Guard {

	/** Depth of allow() calls in progress. */
	private static int $allowed = 0;

	/** "taxonomy|name" already logged this request, so a loop logs once. */
	private static array $logged = [];

	public static function init(): void {
		add_filter( 'pre_insert_term', [ __CLASS__, 'filter' ], 10, 2 );
	}

	/**
	 * Runs $create with the guard off: a person typed or confirmed this term's
	 * name (Taxonomy Mapping's Create & map, the Product Workbench, a taxonomy
	 * import job).
	 *
	 * @param callable $create Calls wp_insert_term().
	 * @return mixed What $create returns.
	 */
	public static function allow( callable $create ) {
		++self::$allowed;
		try {
			return $create();
		} finally {
			--self::$allowed;
		}
	}

	/**
	 * @param string|WP_Error $term     Term name.
	 * @param string          $taxonomy Taxonomy slug.
	 * @return string|WP_Error
	 */
	public static function filter( $term, $taxonomy ) {
		if ( is_wp_error( $term ) || self::$allowed > 0 || ! is_string( $term )
			|| ! in_array( $taxonomy, MMI_Pipeline_Field_Resolver::TAXONOMY_MAPPING_ONLY, true )
			|| MMI_Pipeline_Field_Resolver::term_creation_allowed( $taxonomy )
			|| self::is_core_term_screen() ) {
			return $term;
		}

		$name = trim( $term );
		if ( $name === '' || ! self::is_supplier_value( $name, $taxonomy ) ) {
			return $term;
		}

		self::log_block( $name, $taxonomy );
		return new WP_Error(
			'mmi_taxmap_supplier_value',
			sprintf(
				/* translators: %s: the refused term name */
				__( '"%s" is a value a supplier sends. Map it to a term in the Product Workbench → Category & brand mapping instead of creating a term named after it.', 'mmi-data-pipeline' ),
				$name
			)
		);
	}

	/** WordPress's Products → Categories / Brands screen: a person adding a term by hand. */
	private static function is_core_term_screen(): bool {
		if ( wp_doing_ajax() ) {
			// Read-only: core's own add-tag handler checks the nonce.
			return sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) === 'add-tag'; // phpcs:ignore WordPress.Security.NonceVerification
		}
		return is_admin() && in_array( $GLOBALS['pagenow'] ?? '', [ 'edit-tags.php', 'term.php' ], true );
	}

	private static function is_supplier_value( string $name, string $taxonomy ): bool {
		global $wpdb;

		$table = MMI_DB::tax_mappings_table();
		// audit-ok: runs once per refused-candidate term insert, never per product
		$mapped = $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE wc_taxonomy = %s AND source_value = %s LIMIT 1", $taxonomy, $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from MMI_DB
		if ( $mapped ) {
			return true;
		}

		// meta_key is indexed; the prefix range keeps this to the stored source values.
		return (bool) $wpdb->get_var( $wpdb->prepare(
			"SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_value = %s LIMIT 1",
			$wpdb->esc_like( '_mmi_src_' ) . '%',
			$name
		) );
	}

	private static function log_block( string $name, string $taxonomy ): void {
		$key = $taxonomy . '|' . strtolower( $name );
		if ( isset( self::$logged[ $key ] ) || ! class_exists( 'MMI_Logger' ) ) {
			return;
		}
		self::$logged[ $key ] = true;

		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification -- logged only
		MMI_Logger::warn(
			'Refused to create a term named after a supplier value',
			[
				'taxonomy' => $taxonomy,
				'name'     => $name,
				'request'  => ( defined( 'WP_CLI' ) && WP_CLI ) ? 'wp-cli' : esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) ),
				'action'   => $action,
				'user'     => get_current_user_id(),
				// Nearest frames first: the code that asked for the term.
				'caller'   => implode( ' ← ', array_slice( (array) wp_debug_backtrace_summary( null, 3, false ), 0, 8 ) ),
			],
			'pipeline',
			__CLASS__
		);
	}
}
