<?php
/**
 * A brand's names over time, so title rules survive a brand term rename
 * (owner 2026-10-15: a renamed brand kept its old name in 54 proposals,
 * "Acme Kits Acme Drums Snares" after "Acme Kits" became "Acme Drums").
 *
 * Brand rules (a store's rule pack, its own saved rules) are keyed by the brand term's name in lowercase. A rename used to switch them
 * all off silently. Now:
 *
 * - Renaming a product_brand term records the old name (OPTION, plus filter
 *   mmi_pipeline_brand_former_names for renames before this existed). Lookups try the current name, then the former
 *   names (pick(), is(), in()), so a brand's rules follow the brand.
 * - The former names count as the brand at the start of a title
 *   (forms()), so an applied "Acme Kits …" title is recomposed as
 *   "Acme Drums …" rather than "Acme Drums Acme Kits …".
 * - Rule text says {brand} where it means the brand itself. In a pattern it
 *   matches any form of the brand, current or former (pattern()); in output
 *   it is the brand as written in titles (text()).
 * - A rename queues every product of the brand for recomposition, so the
 *   proposals carry the new name without waiting for the daily run.
 *
 * Name-specific spelling (a store's display names, display()) is not carried
 * over: it describes the old name, not the new one.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BrandNames {

	/** Current name (lowercase) → former names as they were written. Written on rename. */
	const OPTION = 'mmpt_brand_former_names';

	/** Renames made before OPTION existed: lowercase current name => former names (a store adds its own by filter). */
	private static function known(): array {
		$known = apply_filters( 'mmi_pipeline_brand_former_names', [] );
		return is_array( $known ) ? $known : [];
	}

	/** @var array<int,string> term id → name before the edit in progress */
	private static $before = [];

	/** @var array<string,string[]>|null */
	private static $map = null;

	/** @var array<string,string> brand → {brand} pattern (memoized per request) */
	private static $rx = [];

	public static function register_hooks(): void {
		add_action( 'edit_terms', [ __CLASS__, 'before_edit' ], 10, 2 );
		add_action( 'edited_product_brand', [ __CLASS__, 'after_edit' ] );
	}

	public static function norm( string $name ): string {
		return strtolower( trim( html_entity_decode( $name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	/** @return string[] The brand's former names, as they were written. */
	public static function former( string $brand ): array {
		if ( self::$map === null ) {
			$saved     = get_option( self::OPTION, [] );
			self::$map = array_merge_recursive( self::known(), is_array( $saved ) ? $saved : [] );
		}
		$b = self::norm( $brand );
		return array_values( array_filter( array_unique( self::$map[ $b ] ?? [] ), static fn( $n ) => self::norm( (string) $n ) !== $b ) );
	}

	/** @return string[] Lowercase keys this brand's rules may be filed under: current name first. */
	public static function keys( string $brand ): array {
		return array_values( array_unique( array_merge( [ self::norm( $brand ) ], array_map( [ __CLASS__, 'norm' ], self::former( $brand ) ) ) ) );
	}

	/** A brand-keyed table's entry for this brand, under its current or a former name. */
	public static function pick( array $table, string $brand ) {
		foreach ( self::keys( $brand ) as $k ) {
			if ( $k !== '' && isset( $table[ $k ] ) ) {
				return $table[ $k ];
			}
		}
		return null;
	}

	/** Whether $brand is the brand a rule names ($key: lowercase, as in the rule tables). */
	public static function is( string $brand, string $key ): bool {
		return $key !== '' && in_array( strtolower( $key ), self::keys( $brand ), true );
	}

	/** Whether $brand is one of these lowercase names. */
	public static function in( string $brand, array $list ): bool {
		return (bool) array_intersect( self::keys( $brand ), $list );
	}

	/** Replace {brand} in a regex with every form of the brand (current and former, any case). */
	public static function pattern( string $rx, string $brand ): string {
		if ( strpos( $rx, '{brand}' ) === false ) {
			return $rx;
		}
		if ( ! isset( self::$rx[ $brand ] ) ) {
			$forms             = array_map( static fn( $f ) => preg_quote( $f, '/' ), self::forms( $brand ) );
			self::$rx[ $brand ] = $forms ? '(?i:' . implode( '|', $forms ) . ')' : '(?!)';
		}
		return str_replace( '{brand}', self::$rx[ $brand ], $rx );
	}

	/** Replace {brand} in rule output with the brand as written in titles. $replacement: a preg_replace replacement. */
	public static function text( string $s, string $brand, bool $replacement = false ): string {
		if ( strpos( $s, '{brand}' ) === false ) {
			return $s;
		}
		$name = self::display( $brand );
		return str_replace( '{brand}', $replacement ? addcslashes( $name, '\\$' ) : $name, $s );
	}

	/**
	 * Every form a title may write the brand in, longest first: by default its name and former names;
	 * a store's own spellings and short forms come by filter `mmi_pipeline_brand_forms`.
	 *
	 * @return string[]
	 */
	public static function forms( string $brand ): array {
		$default = array_values( array_unique( array_filter( array_merge( [ trim( $brand ) ], self::former( $brand ) ), 'strlen' ) ) );
		usort( $default, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		$forms = apply_filters( 'mmi_pipeline_brand_forms', $default, $brand );
		return is_array( $forms ) ? array_values( $forms ) : $default;
	}

	/** The brand as titles write it: its name, or a store's own spelling (filter `mmi_pipeline_brand_display`). */
	public static function display( string $brand ): string {
		return (string) apply_filters( 'mmi_pipeline_brand_display', trim( html_entity_decode( $brand, ENT_QUOTES, 'UTF-8' ) ), $brand );
	}

	/** Apply a brand's [regex => replacement] rules, {brand} expanded in both. */
	public static function replace( array $rules, string $brand, string $s ): string {
		foreach ( $rules as $rx => $rep ) {
			$s = (string) preg_replace( self::pattern( (string) $rx, $brand ), self::text( (string) $rep, $brand, true ), $s );
		}
		return $s;
	}

	public static function before_edit( $term_id, $taxonomy ): void {
		if ( $taxonomy !== 'product_brand' ) {
			return;
		}
		$t = get_term( (int) $term_id, 'product_brand' );
		if ( $t && ! is_wp_error( $t ) ) {
			self::$before[ (int) $term_id ] = html_entity_decode( (string) $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}

	/** A brand was renamed: remember the old name, move its custom rules, recompose its products. */
	public static function after_edit( $term_id ): void {
		$old = self::$before[ (int) $term_id ] ?? '';
		unset( self::$before[ (int) $term_id ] );
		$t = get_term( (int) $term_id, 'product_brand' );
		if ( $old === '' || ! $t || is_wp_error( $t ) ) {
			return;
		}
		$new = html_entity_decode( (string) $t->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( self::norm( $old ) === self::norm( $new ) ) {
			return; // slug, description or casing only
		}
		$map = get_option( self::OPTION, [] );
		$map = is_array( $map ) ? $map : [];
		$was = array_merge( $map[ self::norm( $old ) ] ?? [], self::known()[ self::norm( $old ) ] ?? [], [ $old ] );
		unset( $map[ self::norm( $old ) ] );
		$map[ self::norm( $new ) ] = array_values( array_unique( array_merge( $map[ self::norm( $new ) ] ?? [], $was ) ) );
		update_option( self::OPTION, $map, true );
		self::$map = null;
		self::$rx  = [];
		CustomRules::rename_brand( $old, $new );
		$ids = get_objects_in_term( (int) $term_id, 'product_brand' );
		if ( is_array( $ids ) && $ids ) {
			Generator::queue( $ids );
		}
		Module::info( 'Brand renamed: rules follow it, its products are queued for recomposition', [ 'term' => (int) $term_id, 'from' => $old, 'to' => $new, 'products' => is_array( $ids ) ? count( $ids ) : 0 ] );
	}
}
