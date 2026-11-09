<?php
/**
 * Title proposals: one row per product with a composed title that differs
 * from its current one, plus the review decision and apply history.
 *
 * A table, not postmeta: the review queue filters and sorts 7k+ rows by
 * status/brand/rule/confidence, which postmeta can't index. The row also
 * keeps the pre-change title (original_title) so any applied title can be
 * reverted exactly.
 *
 * Statuses:
 *   proposed  composed, awaiting review (auto-applies only if every rule it
 *             uses is approved and it clears the confidence/flag gates)
 *   accepted  approved by a person — the applier will write it
 *   edited    a person wrote their own title — the applier writes that
 *   rejected  keep the current title; re-proposed only if the composer's
 *             proposal changes
 *   applied   written to post_title (title locked against imports)
 *   reverted  applied, then put back by a person — never re-proposed
 *   stale     the title changed after composing; next generation recomposes
 *   error     the write failed (message in detail.error)
 *
 * No `--` comments inside CREATE TABLE: dbDelta() splits on them.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Store {

	const DB_VERSION     = '2'; // 2: feed_title, the supplier's name the row was composed with (Generator: a rename reopens it)
	const DB_OPTION      = 'mmpt_db_version';
	const STATUSES       = [ 'proposed', 'accepted', 'edited', 'rejected', 'applied', 'reverted', 'stale', 'error' ];
	const OPEN_STATUSES  = [ 'proposed', 'accepted', 'rejected', 'stale', 'error' ];

	/** Same words, ignoring capitals, spacing and quote marks: "Single-knob" and "Single-Knob" are one decision. */
	public static function same_words( string $a, string $b ): bool {
		$k = static fn( string $s ): string => mb_strtolower( trim( (string) preg_replace( [ '/[“”"‘’\']/u', '/\s+/u' ], [ '', ' ' ], $s ) ) );
		return $k( $a ) === $k( $b );
	}

	/** Settings (MMI_Settings, wp_mmi). */
	const RULES_KEY    = 'mmi_product_titles_rules';
	const APPLY_KEY    = 'mmi_product_titles_apply';
	const GEN_STATE    = 'mmi_admin_product_titles_generate_state';
	const APPLY_STATE  = 'mmi_admin_product_titles_apply_state';

	const APPLY_DEFAULTS = [
		'enabled'        => false,
		'batch_size'     => 25,
		'min_confidence' => 80,
		// Also write proposals nobody reviewed when they pass every gate (auto_eligible_sql()).
		// Off by default (user decision 2026-09-29): only accepted/edited titles are written.
		'auto'           => false,
	];

	/**
	 * WooCommerce product statuses whose titles are worked (owner 2026-10-28: "so all products can be worked"):
	 * drafts, pending and private products get title proposals too. Trash and auto-drafts never do.
	 */
	const WORK_STATUSES = [ 'publish', 'draft', 'pending', 'private' ];

	/** Whether the product's status is one of WORK_STATUSES. */
	public static function is_worked( int $id ): bool {
		return in_array( get_post_status( $id ), self::WORK_STATUSES, true );
	}

	/** Product ids that get a title proposal: every WORK_STATUSES product Product Data doesn't leave out. @return int[] */
	public static function title_ids(): array {
		global $wpdb;
		return Exclusions::filter(
			$wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status IN ('" . implode( "','", self::WORK_STATUSES ) . "') ORDER BY ID ASC" ) // phpcs:ignore WordPress.DB.PreparedSQL -- constants only
		);
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_title_proposals';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  product_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'proposed',
  current_title varchar(500) NOT NULL DEFAULT '',
  proposed_title varchar(500) NOT NULL DEFAULT '',
  edited_title varchar(500) DEFAULT NULL,
  original_title varchar(500) DEFAULT NULL,
  confidence tinyint(3) unsigned NOT NULL DEFAULT 0,
  rules varchar(191) NOT NULL DEFAULT '',
  flags varchar(500) NOT NULL DEFAULT '',
  brand varchar(150) NOT NULL DEFAULT '',
  feed varchar(60) NOT NULL DEFAULT '',
  feed_title varchar(500) NOT NULL DEFAULT '',
  detail longtext,
  dupes smallint(5) unsigned NOT NULL DEFAULT 0,
  run_id varchar(20) NOT NULL DEFAULT '',
  locked_by_us tinyint(1) NOT NULL DEFAULT 0,
  composed_at datetime DEFAULT NULL,
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  applied_at datetime DEFAULT NULL,
  PRIMARY KEY  (product_id),
  KEY status_conf (status, confidence),
  KEY brand (brand),
  KEY applied_at (applied_at)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/* ── Settings ───────────────────────────────────────────────────────── */

	/**
	 * Per rule: `enabled` ("In use": the composer runs it; default on) and
	 * `approved` ("May auto-apply"). Two separate decisions.
	 *
	 * @return array<string,array{enabled:bool,always_on:bool,approved:bool,by:int,at:string,enabled_at:string}>
	 */
	public static function rules(): array {
		$saved = \MMI_Settings::get( self::RULES_KEY, [] );
		$out   = [];
		$pack  = TitleRules::pack();
		$fixed = $pack->always_on();
		foreach ( array_keys( $pack->all_rules() ) as $id ) {
			$r          = is_array( $saved[ $id ] ?? null ) ? $saved[ $id ] : [];
			$always     = in_array( $id, $fixed, true );
			$out[ $id ] = [
				'enabled'    => $always || ! array_key_exists( 'enabled', $r ) || ! empty( $r['enabled'] ),
				'always_on'  => $always,
				'approved'   => ! empty( $r['approved'] ),
				'by'         => (int) ( $r['by'] ?? 0 ),
				'at'         => (string) ( $r['at'] ?? '' ),
				'enabled_at' => (string) ( $r['enabled_at'] ?? '' ),
			];
		}
		return $out;
	}

	public static function set_rule( string $id, bool $approved ): void {
		if ( ! isset( TitleRules::pack()->all_rules()[ $id ] ) ) {
			return;
		}
		$saved = \MMI_Settings::get( self::RULES_KEY, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$keep         = is_array( $saved[ $id ] ?? null ) ? $saved[ $id ] : [];
		$saved[ $id ] = [ 'approved' => $approved, 'by' => get_current_user_id(), 'at' => current_time( 'mysql' ) ] + $keep;
		\MMI_Settings::set( self::RULES_KEY, $saved );
	}

	/** Switch a rule on or off ("In use"); keeps its auto-apply approval. */
	public static function set_rule_enabled( string $id, bool $enabled ): bool {
		if ( ! isset( TitleRules::pack()->all_rules()[ $id ] ) || in_array( $id, TitleRules::pack()->always_on(), true ) ) {
			return false;
		}
		$saved = \MMI_Settings::get( self::RULES_KEY, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$keep  = is_array( $saved[ $id ] ?? null ) ? $saved[ $id ] : [];
		$saved[ $id ] = [ 'enabled' => $enabled, 'enabled_by' => get_current_user_id(), 'enabled_at' => current_time( 'mysql' ) ] + $keep;
		\MMI_Settings::set( self::RULES_KEY, $saved );
		TitleRules::pack()->rules_changed();
		CustomRules::reset();
		return true;
	}

	public static function apply_settings(): array {
		$saved = \MMI_Settings::get( self::APPLY_KEY, [] );
		$s     = array_merge( self::APPLY_DEFAULTS, is_array( $saved ) ? array_intersect_key( $saved, self::APPLY_DEFAULTS ) : [] );
		$s['enabled']        = (bool) $s['enabled'];
		$s['auto']           = (bool) $s['auto'];
		$s['batch_size']     = max( 1, min( 50, (int) $s['batch_size'] ) );
		$s['min_confidence'] = max( 50, min( 100, (int) $s['min_confidence'] ) );
		return $s;
	}

	public static function save_apply_settings( array $in ): array {
		$s = array_merge( self::apply_settings(), array_intersect_key( $in, self::APPLY_DEFAULTS ) );
		\MMI_Settings::set( self::APPLY_KEY, $s );
		return self::apply_settings();
	}

	public static function state( string $key ): array {
		$s = \MMI_Settings::get( $key, [] );
		return is_array( $s ) ? $s : [];
	}

	public static function set_state( string $key, array $patch ): array {
		$s = array_merge( self::state( $key ), $patch );
		\MMI_Settings::set( $key, $s );
		return $s;
	}

	/* ── Auto-apply eligibility (one definition, used by the applier and the UI) ── */

	/**
	 * SQL condition (no leading AND) matching proposed rows every one of whose
	 * rules is approved, above the confidence floor, with no blocking flag.
	 * Returns '0=1' while no rule is approved.
	 */
	public static function auto_eligible_sql(): string {
		global $wpdb;
		$rules    = self::rules();
		$approved = array_keys( array_filter( $rules, static fn( $r ) => $r['approved'] ) );
		if ( ! $approved ) {
			return '0=1';
		}
		$parts = [ "status = 'proposed'", $wpdb->prepare( 'confidence >= %d', self::apply_settings()['min_confidence'] ) ];
		foreach ( array_diff( array_keys( $rules ), $approved ) as $unapproved ) {
			$parts[] = $wpdb->prepare( 'FIND_IN_SET(%s, rules) = 0', $unapproved );
		}
		foreach ( TitleRules::pack()->flags()['blocking'] as $flag ) {
			$parts[] = $wpdb->prepare( 'flags NOT LIKE %s', '%' . $wpdb->esc_like( $flag ) . '%' );
		}
		$parts[] = 'dupes = 0';
		return implode( ' AND ', $parts );
	}

	/* ── Rows ───────────────────────────────────────────────────────────── */

	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE product_id = %d', $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/** @param int[] $ids */
	public static function get_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$rows = $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE product_id IN (' . implode( ',', $ids ) . ')', ARRAY_A );
		$out  = [];
		foreach ( $rows as $r ) {
			$out[ (int) $r['product_id'] ] = self::hydrate( $r );
		}
		return $out;
	}

	public static function update( int $id, array $fields ): void {
		global $wpdb;
		if ( isset( $fields['detail'] ) && is_array( $fields['detail'] ) ) {
			$fields['detail'] = wp_json_encode( $fields['detail'] );
		}
		$wpdb->update( self::table(), $fields, [ 'product_id' => $id ] );
	}

	private static function hydrate( array $r ): array {
		$r['product_id']   = (int) $r['product_id'];
		$r['confidence']   = (int) $r['confidence'];
		$r['dupes']        = (int) $r['dupes'];
		$r['locked_by_us'] = (int) $r['locked_by_us'];
		$r['detail']       = json_decode( (string) $r['detail'], true ) ?: [];
		$r['rules']        = $r['rules'] === '' ? [] : explode( ',', $r['rules'] );
		$r['flags']        = $r['flags'] === '' ? [] : explode( ',', $r['flags'] );
		return $r;
	}

	/**
	 * Filtered, paged listing for the review queue.
	 *
	 * @return array{rows:array,total:int}
	 */
	/** Review-queue stock filter values → WooCommerce stock_status values. */
	/**
	 * Inputs the composer needs from the product itself. Without a brand there is
	 * no brand_prefix; with only the default category the product isn't seen as
	 * software, so no "(Download)"; without a SKU it can't be matched to its
	 * supplier feed (descriptions, sub-category, edition).
	 */
	public const MISSING_INPUTS = [
		'brand'    => 'No brand',
		'category' => 'No category',
		'sku'      => 'No SKU',
	];

	/** WHERE fragment matching rows whose product lacks $key ('any' = at least one). */
	public static function missing_sql( string $key ): string {
		global $wpdb;
		$t   = self::table();
		$def = (int) get_option( 'default_product_cat', 0 );
		$sql = [
			'brand'    => "NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} mbr
				INNER JOIN {$wpdb->term_taxonomy} mbt ON mbt.term_taxonomy_id = mbr.term_taxonomy_id AND mbt.taxonomy = 'product_brand'
				WHERE mbr.object_id = {$t}.product_id)",
			'category' => "NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} mcr
				INNER JOIN {$wpdb->term_taxonomy} mct ON mct.term_taxonomy_id = mcr.term_taxonomy_id AND mct.taxonomy = 'product_cat' AND mct.term_id <> {$def}
				WHERE mcr.object_id = {$t}.product_id)",
			'sku'      => "NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} msk
				WHERE msk.post_id = {$t}.product_id AND msk.meta_key = '_sku' AND msk.meta_value <> '')",
		];
		if ( $key === 'any' ) {
			return '(' . implode( ' OR ', $sql ) . ')';
		}
		return $sql[ $key ] ?? '1=1';
	}

	/**
	 * Which composer inputs each product lacks, read live (not from the stored row),
	 * so assigning a brand clears the mark on the next load.
	 *
	 * @return array<int,string[]> product id → MISSING_INPUTS keys
	 */
	public static function missing_for( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$in   = implode( ',', $ids );
		$def  = (int) get_option( 'default_product_cat', 0 );
		$have = [];
		foreach ( $wpdb->get_results(
			"SELECT DISTINCT tr.object_id, tt.taxonomy FROM {$wpdb->term_relationships} tr
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE tr.object_id IN ({$in}) AND ( tt.taxonomy = 'product_brand' OR ( tt.taxonomy = 'product_cat' AND tt.term_id <> {$def} ) )",
			ARRAY_A
		) as $r ) {
			$have[ (int) $r['object_id'] ][ $r['taxonomy'] === 'product_brand' ? 'brand' : 'category' ] = true;
		}
		foreach ( $wpdb->get_col( "SELECT post_id FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key = '_sku' AND meta_value <> ''" ) as $pid ) {
			$have[ (int) $pid ]['sku'] = true;
		}
		$out = [];
		foreach ( $ids as $id ) {
			$out[ $id ] = array_values( array_diff( array_keys( self::MISSING_INPUTS ), array_keys( $have[ $id ] ?? [] ) ) );
		}
		return $out;
	}

	public const STOCK_FILTERS = [
		'instock'    => [ 'instock', 'onbackorder' ],
		'outofstock' => [ 'outofstock' ],
	];

	/**
	 * Stock status per product, in one query (a page of the review queue).
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int,string> product_id => instock|outofstock|onbackorder
	 */
	public static function stock_statuses( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, stock_status FROM {$wpdb->wc_product_meta_lookup} WHERE product_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return array_column( $rows, 'stock_status', 'product_id' );
	}

	/**
	 * What the Review table shows per product beyond the proposal row — categories, brands
	 * and price — for a page of rows at once (Rest::shape() reads it).
	 *
	 * @param int[] $ids Product IDs.
	 * @return array{cats:array,brands:array,prices:array<int,string>}
	 */
	public static function row_terms( array $ids ): array {
		return [ 'cats' => self::terms_for( $ids, 'product_cat' ), 'brands' => self::terms_for( $ids, 'product_brand' ), 'prices' => self::prices_for( $ids ) ];
	}

	/**
	 * The price shoppers see, formatted ("$49.00", or "$49.00 – $99.00" for a variable
	 * product), from the WooCommerce lookup table in one query.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int,string>
	 */
	public static function prices_for( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT product_id, min_price, max_price FROM {$wpdb->wc_product_meta_lookup} WHERE product_id IN (" . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')', ...$ids ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$fmt  = static fn( $p ) => html_entity_decode( wp_strip_all_tags( wc_price( (float) $p ) ), ENT_QUOTES, 'UTF-8' );
		$out  = [];
		foreach ( $rows as $r ) {
			if ( $r['min_price'] === null || $r['min_price'] === '' ) {
				continue;
			}
			$out[ (int) $r['product_id'] ] = (float) $r['max_price'] > (float) $r['min_price'] ? $fmt( $r['min_price'] ) . ' – ' . $fmt( $r['max_price'] ) : $fmt( $r['min_price'] );
		}
		return $out;
	}

	/**
	 * product_cat terms per product, in one query (a page of the review queue).
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int,array<int,array{id:int,name:string}>>
	 */
	public static function categories_for( array $ids ): array {
		return self::terms_for( $ids, 'product_cat' );
	}

	/**
	 * A taxonomy's terms per product (product_cat, product_brand), in one query.
	 *
	 * @param int[] $ids Product IDs.
	 * @return array<int,array<int,array{id:int,name:string}>>
	 */
	public static function terms_for( array $ids, string $taxonomy ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids || ! taxonomy_exists( $taxonomy ) ) {
			return [];
		}
		$terms = wp_get_object_terms( $ids, $taxonomy, [ 'fields' => 'all_with_object_id', 'orderby' => 'name' ] );
		$out   = [];
		foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
			$out[ (int) $t->object_id ][] = [ 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) ];
		}
		return $out;
	}

	/** Every product_cat term as a flat, tree-ordered list for the category picker. */
	public static function category_tree(): array {
		return self::term_tree( 'product_cat' );
	}

	/** Every term of a taxonomy as a flat, tree-ordered list for the Review table's pickers. */
	public static function term_tree( string $taxonomy ): array {
		$terms = taxonomy_exists( $taxonomy ) ? get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ] ) : [];
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		$kids = [];
		foreach ( $terms as $t ) {
			$kids[ (int) $t->parent ][] = $t;
		}
		$out  = [];
		$walk = static function ( int $parent, int $depth, string $path ) use ( &$walk, &$out, $kids ) {
			foreach ( $kids[ $parent ] ?? [] as $t ) {
				$name  = html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' );
				$full  = $path === '' ? $name : $path . ' › ' . $name;
				$out[] = [ 'id' => (int) $t->term_id, 'name' => $name, 'path' => $full, 'depth' => $depth ];
				$walk( (int) $t->term_id, $depth + 1, $full );
			}
		};
		$walk( 0, 0, '' );
		return $out;
	}

	public static function query( array $f ): array {
		global $wpdb;
		$where = [ '1=1' . Exclusions::sql_not_excluded() ];
		$args  = [];
		$status = (string) ( $f['status'] ?? '' );
		if ( $status === 'auto' ) {
			$where[] = self::auto_eligible_sql();
		} elseif ( $status === 'any' ) {
			// every status (the Part numbers tab's "Edit title" links need applied/accepted rows too)
		} elseif ( $status === 'open' || $status === '' ) {
			// Still waiting for a decision. Accepted/edited rows leave this view; they're under "queued".
			$where[] = "status IN ('proposed','stale','error')";
		} elseif ( $status === 'changed' ) {
			// Reviewed once, then a rule changed the title for real: back for another look.
			$where[] = "status = 'proposed' AND reviewed_at IS NOT NULL";
		} elseif ( $status === 'queued' ) {
			$where[] = "status IN ('accepted','edited')";
		} elseif ( in_array( $status, self::STATUSES, true ) ) {
			$where[] = 'status = %s';
			$args[]  = $status;
		}
		if ( ! empty( $f['rule'] ) && isset( TitleRules::pack()->all_rules()[ $f['rule'] ] ) ) {
			$where[] = 'FIND_IN_SET(%s, rules) > 0';
			$args[]  = $f['rule'];
		}
		if ( ! empty( $f['only_rule'] ) && isset( TitleRules::pack()->all_rules()[ $f['only_rule'] ] ) ) {
			$where[] = 'rules = %s';
			$args[]  = $f['only_rule'];
		}
		if ( ! empty( $f['brand'] ) ) {
			$where[] = 'brand = %s';
			$args[]  = $f['brand'];
		}
		if ( isset( $f['ids'] ) && is_array( $f['ids'] ) ) { // only these products (One page's scope); none = no rows
			$ids     = array_filter( array_map( 'absint', $f['ids'] ) );
			$where[] = $ids ? 'product_id IN (' . implode( ',', $ids ) . ')' : '0=1';
		}
		if ( ! empty( $f['flag'] ) ) {
			if ( $f['flag'] === 'any' ) {
				$where[] = "flags <> ''";
			} elseif ( $f['flag'] === 'duplicate_title' ) {
				$where[] = 'dupes > 0';
			} else {
				$where[] = 'flags LIKE %s';
				$args[]  = '%' . $wpdb->esc_like( (string) $f['flag'] ) . '%';
			}
		}
		// WooCommerce's lookup table (indexed on stock_status); backorder counts as in stock, since it can still be bought.
		$stock = self::STOCK_FILTERS[ $f['stock'] ?? '' ] ?? null;
		if ( $stock ) {
			$where[] = "product_id IN (SELECT product_id FROM {$wpdb->wc_product_meta_lookup} WHERE stock_status IN ('" . implode( "','", $stock ) . "'))";
		}
		if ( ! empty( $f['missing'] ) && ( $f['missing'] === 'any' || isset( self::MISSING_INPUTS[ $f['missing'] ] ) ) ) {
			$where[] = self::missing_sql( (string) $f['missing'] );
		}
		$post_status = in_array( $f['post_status'] ?? '', self::WORK_STATUSES, true ) ? (string) $f['post_status'] : ''; // '' = every worked status
		if ( isset( $f['max_conf'] ) && $f['max_conf'] !== '' ) {
			$where[] = 'confidence <= %d';
			$args[]  = (int) $f['max_conf'];
		}
		if ( isset( $f['min_conf'] ) && $f['min_conf'] !== '' ) {
			$where[] = 'confidence >= %d';
			$args[]  = (int) $f['min_conf'];
		}
		if ( ! empty( $f['q'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $f['q'] ) . '%';
			$where[] = '(current_title LIKE %s OR proposed_title LIKE %s OR product_id = %d)';
			array_push( $args, $like, $like, (int) $f['q'] );
		}
		$t      = self::table();
		$counts = null;
		if ( ! empty( $f['post_status_counts'] ) ) {
			// Rows per WooCommerce status under every other filter, for the Product status filter's options.
			$sql    = "SELECT pst.post_status k, COUNT(*) n FROM {$t} JOIN {$wpdb->posts} pst ON pst.ID = {$t}.product_id WHERE " . implode( ' AND ', $where ) . ' GROUP BY pst.post_status';
			$counts = array_map( 'intval', array_column( (array) $wpdb->get_results( $args ? $wpdb->prepare( $sql, ...$args ) : $sql, ARRAY_A ), 'n', 'k' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
		if ( $post_status !== '' ) {
			$where[] = "product_id IN (SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = %s)";
			$args[]  = $post_status;
		}
		$order_cols = [
			'confidence' => 'confidence',
			'brand'      => 'brand',
			'current'    => 'current_title',
			'proposed'   => 'proposed_title',
			'status'     => 'status',
			'id'         => 'product_id',
			'applied'    => 'applied_at',
			'rules'      => 'rules',
			// The product's alphabetically first category — what the Category column lists first.
			'category'   => "(SELECT MIN(ct.name) FROM {$wpdb->term_relationships} ctr
				INNER JOIN {$wpdb->term_taxonomy} ctt ON ctt.term_taxonomy_id = ctr.term_taxonomy_id AND ctt.taxonomy = 'product_cat'
				INNER JOIN {$wpdb->terms} ct ON ct.term_id = ctt.term_id
				WHERE ctr.object_id = {$t}.product_id)",
			'stock'      => "(SELECT sl.stock_status FROM {$wpdb->wc_product_meta_lookup} sl WHERE sl.product_id = {$t}.product_id)",
			'price'      => "(SELECT pl.min_price FROM {$wpdb->wc_product_meta_lookup} pl WHERE pl.product_id = {$t}.product_id)",
		];
		$orderby    = $order_cols[ $f['orderby'] ?? '' ] ?? 'confidence';
		$order      = strtolower( (string) ( $f['order'] ?? '' ) ) === 'asc' ? 'ASC' : 'DESC';
		$per        = max( 10, min( 200, (int) ( $f['per_page'] ?? 50 ) ) );
		$page       = max( 1, (int) ( $f['page'] ?? 1 ) );
		$sql_where  = implode( ' AND ', $where );

		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$sql_where}";
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$list_sql  = "SELECT * FROM {$t} WHERE {$sql_where} ORDER BY {$orderby} {$order}, product_id ASC LIMIT %d OFFSET %d";
		$rows      = $wpdb->get_results( $wpdb->prepare( $list_sql, ...array_merge( $args, [ $per, ( $page - 1 ) * $per ] ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		return [ 'rows' => array_map( [ __CLASS__, 'hydrate' ], $rows ), 'total' => $total ] + ( $counts === null ? [] : [ 'post_statuses' => $counts ] );
	}

	/** Description rows by status plus vendor-page fetch outcomes (two small GROUP BYs on an indexed table). */
	/** Vendor URL candidates: used automatically, waiting for a person, approved, rejected — plus the last finder run. */
	public static function url_counts(): array {
		global $wpdb;
		$t = UrlFinder::table();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) !== $t ) {
			return [];
		}
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT SUM(status = 'candidate' AND score >= %d) auto, SUM(status = 'candidate' AND score < %d) review, SUM(status = 'approved') approved, SUM(status = 'rejected') rejected FROM {$t}", UrlFinder::AUTO_SCORE, UrlFinder::AUTO_SCORE ),
			ARRAY_A
		) ?: [];
		return array_map( 'intval', $row ) + [ 'last_run' => self::state( UrlFinder::STATE )['last_run'] ?? '' ];
	}

	public static function description_counts(): array {
		global $wpdb;
		$t   = Descriptions::table();
		$out = [ 'status' => [], 'fetch' => [] ];
		foreach ( $wpdb->get_results( "SELECT status k, COUNT(*) n FROM {$t} GROUP BY status", ARRAY_A ) as $r ) {
			$out['status'][ $r['k'] ] = (int) $r['n'];
		}
		foreach ( $wpdb->get_results( "SELECT fetch_status k, COUNT(*) n FROM {$t} WHERE fetch_status <> '' GROUP BY fetch_status", ARRAY_A ) as $r ) {
			$out['fetch'][ $r['k'] ] = (int) $r['n'];
		}
		return $out;
	}

	/** Counts for the Rules / Overview panels — one aggregate query, run from REST, never the page render. */
	public static function summary(): array {
		global $wpdb;
		$t      = self::table();
		$status = $wpdb->get_results( "SELECT status, COUNT(*) n FROM {$t} GROUP BY status", ARRAY_A );
		$by     = [];
		foreach ( $status as $s ) {
			$by[ $s['status'] ] = (int) $s['n'];
		}
		$catalog = TitleRules::pack()->all_rules();
		$sums    = [];
		foreach ( array_keys( $catalog ) as $id ) {
			$sums[] = $wpdb->prepare( 'SUM(FIND_IN_SET(%s, rules) > 0) AS %i', $id, 'r_' . $id );
			$sums[] = $wpdb->prepare( 'SUM(rules = %s) AS %i', $id, 'o_' . $id );
		}
		$rule_row = $wpdb->get_row( 'SELECT ' . implode( ', ', $sums ) . " FROM {$t} WHERE status IN ('proposed','accepted','edited','stale')", ARRAY_A ) ?: [];
		$rules    = [];
		foreach ( self::rules() as $id => $r ) {
			$rules[ $id ] = $r + [
				'label'       => $catalog[ $id ][0],
				'description' => $catalog[ $id ][1],
				'count'       => (int) ( $rule_row[ 'r_' . $id ] ?? 0 ),
				'only'        => (int) ( $rule_row[ 'o_' . $id ] ?? 0 ),
			];
		}
		$flags = [];
		$known = TitleRules::pack()->flags();
		foreach ( array_merge( $known['blocking'], $known['shown'] ) as $flag ) {
			$flags[ $flag ] = $flag === 'duplicate_title'
				? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE dupes > 0 AND status IN ('proposed','accepted','edited','stale')" )
				: (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE flags LIKE %s AND status IN ('proposed','accepted','edited','stale')", '%' . $wpdb->esc_like( $flag ) . '%' ) );
		}
		$brands = $wpdb->get_col( "SELECT brand FROM {$t} WHERE brand <> '' GROUP BY brand ORDER BY brand ASC LIMIT 500" );
		$auto   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE " . self::auto_eligible_sql() ); // phpcs:ignore WordPress.DB.PreparedSQL

		return [
			'by_status' => $by,
			'changed'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE status = 'proposed' AND reviewed_at IS NOT NULL" ),
			'rules'     => $rules,
			'flags'     => $flags,
			'brands'    => $brands,
			// Excluded brands have no proposals, so the Review filter lists them separately (disabled).
			'excluded_brands' => array_values( array_filter( array_map( static fn( $tid ) => ( $t = get_term( $tid, 'product_brand' ) ) && ! is_wp_error( $t ) ? html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ) : '', Exclusions::get()['brands'] ) ) ),
			'auto'      => $auto,
			'apply'     => self::apply_settings(),
			'today'     => current_time( 'Y-m-d' ),
			'desc'      => self::description_counts(),
			'fetch'     => self::state( 'mmi_admin_product_titles_fetch_state' ),
			'picker'    => self::state( Picker::STATE ),
			'urls'      => self::url_counts(),
			'gen_state' => self::state( self::GEN_STATE ),
			'app_state' => self::state( self::APPLY_STATE ),
		];
	}
}
