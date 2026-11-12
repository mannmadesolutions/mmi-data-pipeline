<?php
/**
 * Issues a product shows in the Workbench, each with the fixes a person can make right there
 * (owner 2026-11-12: "from the Product Workbench, the user needs to be able to address and fix all
 * issues directly"). One list per product, built from what is live now (no brand, no category, no
 * SKU) and the title composer's flags (what they mean comes from the rule pack: TitleRules::issue()).
 *
 * A fix is one of:
 *   brand    the brand panel: pick a brand, or create one (name, logo, website, description), and
 *            map the supplier's value to it so its other products and future imports get it too
 *   cats     the category panel: pick categories or create one, and map the supplier's value
 *   sku      type a SKU
 *   describe pick the descriptor phrase from the maker's or supplier's copy (word for word)
 *   title    edit the title (the Review table's editor)
 *   action   one click, done on the server: `virtual:yes|no`, `add_cat:{term id}`
 *
 * Routes (mmpt/v1, manage_woocommerce): GET /products/{id}/fix-context?kind=brand|cats|describe
 * and POST /products/{id}/fix. An `action` is only run when the product's own issues offer it.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Issues {

	/** Live gaps (Store::missing_for()): what they cost, and the fix. */
	const MISSING = [
		'brand'    => [ 'No brand', 'The brand can’t be put first, the brand page doesn’t list it and Reverb won’t list it.', 'brand', 'Set the brand…' ],
		'category' => [ 'No category', 'Only the default category: the rules can’t tell what the product is and shoppers can’t browse to it.', 'cats', 'Set the category…' ],
		'sku'      => [ 'No SKU', 'Without a SKU the supplier’s data can’t be matched to it.', 'sku', 'Add a SKU…' ],
	];

	/** The engine's own flags (BasicTitleRules, Generator); a pack's issue() wins. */
	const ENGINE = [
		'over_length'      => [ 'Too long', 'Longer than search results show (about 70 characters): shorten it.' ],
		'claim_guard'      => [ 'Would drop a claim', 'The rules would take out words the current title promises, so the title is left alone. Edit it yourself.' ],
		'brand_elsewhere'  => [ 'Brand not first', 'The brand is in the title but not at the start.' ],
		'supplier_renamed' => [ 'Supplier renamed it', 'The supplier changed the product’s name since we wrote its title. Accept the new title or edit it.' ],
	];

	/** Stored flags that a live check replaces (the row's no_brand was composed earlier; `missing` is read now). */
	const SUPERSEDED = [ 'no_brand' ];

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( Rest::CAP );
		$id    = [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ];
		register_rest_route( Rest::NS, '/products/(?P<id>\d+)/fix-context', [ 'methods' => 'GET', 'permission_callback' => $admin, 'args' => $id, 'callback' => [ __CLASS__, 'context' ] ] );
		register_rest_route( Rest::NS, '/products/(?P<id>\d+)/fix', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $id, 'callback' => [ __CLASS__, 'fix' ] ] );
	}

	/* ── The list ─────────────────────────────────────────────────────────── */

	/**
	 * A proposal row's issues: live gaps first, then its flags, then a shared title.
	 *
	 * @param array    $row     Proposal row (Store::get()), flags already split.
	 * @param string[] $missing Store::missing_for() keys for the product.
	 * @return array<int,array{key:string,label:string,help:string,tone:string,fixes:array}>
	 */
	public static function for_row( array $row, array $missing ): array {
		$id  = (int) $row['product_id'];
		$out = self::for_missing( $missing );
		foreach ( (array) $row['flags'] as $raw ) {
			$raw = (string) $raw;
			if ( $raw === '' ) {
				continue;
			}
			[ $flag, $detail ] = array_pad( explode( ':', $raw, 2 ), 2, '' );
			if ( in_array( $flag, self::SUPERSEDED, true ) ) {
				continue;
			}
			$out[] = self::describe_flag( $flag, $detail, $id ) + [ 'key' => $flag ];
		}
		if ( (int) ( $row['dupes'] ?? 0 ) > 0 ) {
			$n     = (int) $row['dupes'];
			$out[] = [
				'key'   => 'duplicate_title',
				'label' => 'Same title as another',
				'help'  => sprintf( _n( 'One other product would get this exact title. Make them differ, or link them as duplicates.', '%d other products would get this exact title. Make them differ, or link them as duplicates.', $n, 'mmi-data-pipeline' ), $n ),
				'tone'  => 'warning',
				'fixes' => [ self::title_fix() ],
			];
		}
		return array_map( [ __CLASS__, 'normalize' ], $out );
	}

	/** Issues for live gaps alone (the Workbench's results rows have no proposal). */
	public static function for_missing( array $missing ): array {
		$out = [];
		foreach ( $missing as $key ) {
			if ( isset( self::MISSING[ $key ] ) ) {
				[ $label, $help, $kind, $fix ] = self::MISSING[ $key ];
				$out[] = [ 'key' => $key, 'label' => $label, 'help' => $help, 'tone' => 'error', 'fixes' => [ [ 'kind' => $kind, 'label' => $fix, 'primary' => true ] ] ];
			}
		}
		return $out;
	}

	/** A flag as an issue (label, help, tone, fixes): the pack's words first, then the engine's (also Waiting). */
	public static function describe_flag( string $flag, string $detail, int $id ): array {
		$pack = TitleRules::pack()->issue( $flag, $detail, $id );
		if ( $pack ) {
			return $pack;
		}
		if ( isset( self::ENGINE[ $flag ] ) ) {
			[ $label, $help ] = self::ENGINE[ $flag ];
			$fixes            = [ self::title_fix() ];
			if ( $flag === 'brand_elsewhere' ) {
				$fixes[] = [ 'kind' => 'brand', 'label' => 'Change the brand…' ];
			}
			if ( $flag === 'claim_guard' && $detail !== '' ) {
				$help .= ' (' . str_replace( '+', ', ', $detail ) . ')';
			}
			return [ 'label' => $label, 'help' => $help, 'tone' => 'warning', 'fixes' => $fixes ];
		}
		// Nobody described it: say what it is called and let the person edit the title.
		return [ 'label' => ucfirst( str_replace( '_', ' ', $flag ) ), 'help' => $detail !== '' ? str_replace( '+', ', ', $detail ) : '', 'tone' => 'warning', 'fixes' => [ self::title_fix() ] ];
	}

	public static function title_fix( string $label = 'Edit the title' ): array {
		return [ 'kind' => 'title', 'label' => $label ];
	}

	public static function normalize( array $i ): array {
		$i['tone']  = in_array( $i['tone'] ?? '', [ 'error', 'warning', 'info' ], true ) ? $i['tone'] : 'warning';
		$i['help']  = (string) ( $i['help'] ?? '' );
		$i['fixes'] = array_values(
			array_filter(
				(array) ( $i['fixes'] ?? [] ),
				static fn( $f ) => is_array( $f ) && in_array( $f['kind'] ?? '', [ 'brand', 'cats', 'sku', 'describe', 'title', 'action' ], true ) && ( ( $f['kind'] ?? '' ) !== 'action' || ! empty( $f['action'] ) )
			)
		);
		if ( ! $i['fixes'] ) {
			$i['fixes'] = [ self::title_fix() ];
		}
		return $i;
	}

	/* ── What a fix panel needs ──────────────────────────────────────────── */

	public static function context( \WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$kind = sanitize_key( (string) $r->get_param( 'kind' ) );
		if ( get_post_type( $id ) !== 'product' ) {
			return new \WP_Error( 'mmpt_bad', __( 'Unknown product.', 'mmi-data-pipeline' ), [ 'status' => 404 ] );
		}
		$base = [ 'id' => $id, 'title' => html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 'can_create' => current_user_can( 'manage_product_terms' ) ];
		switch ( $kind ) {
			case 'brand':
				$feed = self::feed_values( $id, 'product_brand' );
				$seed = $feed ? $feed[0]['value'] : '';
				return rest_ensure_response( $base + [ 'feed' => $feed, 'similar' => self::similar_brands( array_column( $feed, 'value' ) ), 'suggested' => self::brand_name( $seed ) ] );
			case 'cats':
				return rest_ensure_response( $base + [ 'feed' => self::feed_values( $id, 'product_cat' ) ] );
			case 'describe':
				$in     = Descriptions::inputs( [ $id ] )[ $id ] ?? [ 'blocks' => [] ];
				$labels = Descriptions::SOURCE_LABELS;
				$blocks = [];
				foreach ( (array) $in['blocks'] as $i => $b ) {
					$blocks[] = [ 'index' => $i, 'type' => (string) $b['type'], 'label' => ucfirst( $labels[ $b['type'] ] ?? str_replace( '_', ' ', (string) $b['type'] ) ), 'url' => (string) ( $b['url'] ?? '' ), 'text' => mb_substr( (string) $b['text'], 0, 4000 ) ];
				}
				return rest_ensure_response( $base + [ 'blocks' => $blocks ] );
		}
		return new \WP_Error( 'mmpt_bad', __( 'Unknown fix.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
	}

	/**
	 * What the product's suppliers send for $taxonomy, from the raw values imports keep in
	 * `_mmi_src_{field}`: which term that value maps to now (if any), and how many of that supplier's
	 * products sending the same value still have no such term (they get it when the mapping is saved).
	 *
	 * @return array<int,array{supplier:string,label:string,field:string,value:string,mapped:?array,others:int}>
	 */
	public static function feed_values( int $id, string $taxonomy ): array {
		global $wpdb;
		if ( ! class_exists( '\MMI_Pipeline_Field_Mapping_Defaults' ) || ! class_exists( '\MMI_DB' ) ) {
			return [];
		}
		$out = [];
		foreach ( \MMI_Pipeline_Field_Mapping_Defaults::get_taxonomy_source_fields() as $src ) {
			if ( $src['wc_taxonomy'] !== $taxonomy || get_post_meta( $id, '_mmi_supplier_sku_' . $src['supplier'], true ) === '' ) {
				continue;
			}
			$key   = '_mmi_src_' . str_replace( '+', '_', $src['source_field'] );
			$value = trim( (string) get_post_meta( $id, $key, true ) );
			if ( $value === '' ) {
				continue;
			}
			$mapped = (int) \MMI_DB::resolve_tax_mapping( $src['supplier'], $src['source_field'], $value, $taxonomy );
			$term   = $mapped > 0 ? get_term( $mapped, $taxonomy ) : null;
			$def    = (int) get_option( 'default_product_cat', 0 );
			$lacks  = $taxonomy === 'product_cat'
				? "NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat' AND tt.term_id <> {$def} WHERE tr.object_id = m.post_id)"
				: "NOT EXISTS (SELECT 1 FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' WHERE tr.object_id = m.post_id)";
			$others = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(DISTINCT m.post_id) FROM {$wpdb->postmeta} m
					 JOIN {$wpdb->postmeta} s ON s.post_id = m.post_id AND s.meta_key = %s
					 JOIN {$wpdb->posts} p ON p.ID = m.post_id AND p.post_type = 'product' AND p.post_status NOT IN ('trash','auto-draft')
					 WHERE m.meta_key = %s AND m.meta_value = %s AND BINARY m.meta_value = %s AND m.post_id <> %d AND {$lacks}", // phpcs:ignore WordPress.DB.PreparedSQL -- $lacks is built from constants and an int
					'_mmi_supplier_sku_' . $src['supplier'],
					$key,
					$value,
					$value,
					$id
				)
			);
			$out[] = [
				'supplier' => (string) $src['supplier'],
				'label'    => (string) preg_replace( '/\s+—.*$/u', '', (string) $src['label'] ),
				'field'    => (string) $src['source_field'],
				'value'    => $value,
				'mapped'   => $term && ! is_wp_error( $term ) ? [ 'id' => (int) $term->term_id, 'name' => html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ] : null,
				'others'   => $others,
			];
		}
		return $out;
	}

	/** Brands whose names are close to what the supplier sent (case, spacing, punctuation, a typo). */
	public static function similar_brands( array $values ): array {
		$norm   = static fn( string $s ) => (string) preg_replace( '/[^a-z0-9]+/', '', strtolower( remove_accents( html_entity_decode( $s, ENT_QUOTES, 'UTF-8' ) ) ) );
		$wanted = array_values( array_filter( array_map( $norm, $values ) ) );
		if ( ! $wanted ) {
			return [];
		}
		$terms = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] );
		$hits  = [];
		foreach ( is_wp_error( $terms ) ? [] : $terms as $t ) {
			$n = $norm( $t->name );
			if ( $n === '' ) {
				continue;
			}
			$best = 0;
			foreach ( $wanted as $w ) {
				if ( $n === $w ) {
					$best = 100;
				} elseif ( strlen( $w ) >= 4 && strlen( $n ) >= 4 && ( str_contains( $n, $w ) || str_contains( $w, $n ) ) ) {
					$best = max( $best, 80 );
				} elseif ( strlen( $w ) >= 5 && levenshtein( $n, $w ) <= 2 ) {
					$best = max( $best, 70 );
				}
			}
			if ( $best ) {
				$hits[] = [ 'id' => (int) $t->term_id, 'name' => html_entity_decode( $t->name, ENT_QUOTES, 'UTF-8' ), 'score' => $best, 'count' => (int) $t->count ];
			}
		}
		usort( $hits, static fn( $a, $b ) => [ $b['score'], $b['count'] ] <=> [ $a['score'], $a['count'] ] );
		return array_slice( $hits, 0, 6 );
	}

	/** A supplier's brand value as a starting name: shouting is calmed ("FREQPORT" → "Freqport"), anything else kept. */
	public static function brand_name( string $value ): string {
		$value = trim( (string) preg_replace( '/\s+/u', ' ', $value ) );
		return preg_match( '/\p{Ll}/u', $value ) || mb_strlen( $value ) <= 4 ? $value : mb_convert_case( mb_strtolower( $value ), MB_CASE_TITLE, 'UTF-8' );
	}

	/* ── Fixing ───────────────────────────────────────────────────────────── */

	public static function fix( \WP_REST_Request $r ) {
		$id   = (int) $r['id'];
		$kind = sanitize_key( (string) $r->get_param( 'kind' ) );
		if ( get_post_type( $id ) !== 'product' ) {
			return new \WP_Error( 'mmpt_bad', __( 'Unknown product.', 'mmi-data-pipeline' ), [ 'status' => 404 ] );
		}
		switch ( $kind ) {
			case 'brand':
				$done = self::fix_terms( $id, 'product_brand', $r );
				break;
			case 'cats':
				$done = self::fix_terms( $id, 'product_cat', $r );
				break;
			case 'sku':
				$done = self::fix_sku( $id, (string) $r->get_param( 'sku' ) );
				break;
			case 'describe':
				$done = self::fix_describe( $id, (string) $r->get_param( 'phrase' ), (int) $r->get_param( 'block' ) );
				break;
			case 'image':
			case 'gallery':
				$done = self::fix_pictures( $id, $kind, array_values( array_filter( array_map( 'absint', (array) $r->get_param( 'attachments' ) ) ) ) );
				break;
			case 'props':
				$done = self::fix_props( $id, (array) $r->get_param( 'props' ) );
				break;
			case 'action':
				$done = self::fix_action( $id, (string) $r->get_param( 'action' ) );
				break;
			default:
				$done = new \WP_Error( 'mmpt_bad', __( 'Unknown fix.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		wc_delete_product_transients( $id );
		$row = Generator::recompose_one( $id );
		// The supplier's other products the mapping just gave the brand or category: their title
		// proposals were composed without it ("No brand", no "Brand first"). Recomposed now, in this
		// request, so the person sees them change with this one; the term-change hook had only queued
		// them for a background job that can wait behind a busy queue (2026-11-12: five Synapse Audio
		// proposals still read "No brand" an hour after the brand was created from the first).
		$others = array_values( array_diff( array_unique( (array) ( $done['others']['ids'] ?? [] ) ), [ $id ] ) );
		if ( $others ) {
			foreach ( $others as $o ) {
				wc_delete_product_transients( (int) $o );
			}
			$re = Generator::recompose_now( $others );
			$done['others']['ids'] = $others;
			$n  = (int) ( $re['changed'] ?? 0 ) + (int) ( $re['new'] ?? 0 ) + (int) ( $re['reopened'] ?? 0 );
			if ( $n ) {
				$done['message'] .= ' ' . sprintf( _n( '%d other title proposal was updated.', '%d other title proposals were updated.', $n, 'mmi-data-pipeline' ), $n );
			}
		}
		Module::info( 'Product issue fixed from the Workbench', [ 'product' => $id, 'kind' => $kind, 'by' => get_current_user_id() ] + array_diff_key( $done, [ 'message' => 1 ] ) );
		return rest_ensure_response(
			$done + [
				'id'      => $id,
				'row'     => $row ? Rest::shape( $row, Store::stock_statuses( [ $id ] ), Store::row_terms( [ $id ] ) ) : null,
				'removed' => ! $row,
				'missing' => Store::missing_for( [ $id ] )[ $id ] ?? [],
			]
		);
	}

	/**
	 * Brand or categories: an existing term or a new one, written to the product (locked against
	 * imports, as every person's term change from the Review table is), and, when asked, the
	 * supplier's value mapped to it so the supplier's other products with that value get it now and
	 * every import after does too (the Taxonomy Mapper's own save + apply).
	 */
	private static function fix_terms( int $id, string $taxonomy, \WP_REST_Request $r ) {
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ) ) ) );
		$create = $r->get_param( 'create' );
		$made   = null;
		if ( is_array( $create ) && trim( (string) ( $create['name'] ?? '' ) ) !== '' ) {
			$made = $taxonomy === 'product_brand' ? self::create_brand( $create ) : self::create_category( $create );
			if ( is_wp_error( $made ) ) {
				return $made;
			}
			$ids = $taxonomy === 'product_brand' ? [ $made ] : array_values( array_unique( array_merge( [ $made ], $ids ) ) );
		}
		if ( ! $ids ) {
			return new \WP_Error( 'mmpt_bad', $taxonomy === 'product_cat' ? __( 'Choose or create a category.', 'mmi-data-pipeline' ) : __( 'Choose or create a brand.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		foreach ( $ids as $t ) {
			if ( ! term_exists( $t, $taxonomy ) ) {
				return new \WP_Error( 'mmpt_bad', __( 'One of those terms no longer exists.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
			}
		}
		// A real category chosen: the default one ("Uncategorized") goes, or the product still reads as uncategorized.
		$def = (int) get_option( 'default_product_cat', 0 );
		$real = array_values( array_diff( $ids, [ $def ] ) );
		if ( $taxonomy === 'product_cat' && $real ) {
			$ids = $real;
		}
		$set = Rest::write_terms( $id, $taxonomy, $ids, false );
		if ( is_wp_error( $set ) ) {
			return $set;
		}

		// Map the supplier's values the person ticked (only values this product really carries).
		$mapped = [];
		$others = [ 'updated' => 0, 'locked' => 0, 'queued' => false, 'ids' => [] ];
		$want   = array_map( 'strval', (array) $r->get_param( 'remember' ) );
		if ( $want ) {
			$term = $ids[0]; // the new term, or the first one chosen
			foreach ( self::feed_values( $id, $taxonomy ) as $fv ) {
				if ( ! in_array( $fv['supplier'] . '|' . $fv['field'], $want, true ) ) {
					continue;
				}
				if ( ! \MMI_DB::set_tax_mapping( $fv['supplier'], $fv['field'], $fv['value'], $taxonomy, $term, false, '' ) ) {
					return new \WP_Error( 'mmpt_map', __( 'The product was changed, but saving the supplier mapping failed.', 'mmi-data-pipeline' ), [ 'status' => 500 ] );
				}
				$mapped[] = $fv['label'] . ' “' . $fv['value'] . '”';
				if ( class_exists( '\MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler' ) ) {
					$a = \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::apply_saved_mapping( $fv['supplier'], $fv['field'], $fv['value'], $taxonomy, $term, [ $taxonomy ] );
					$others['updated'] += (int) $a['updated'];
					$others['ids']      = array_merge( $others['ids'], array_map( 'intval', (array) ( $a['ids'] ?? [] ) ) );
					// This product sends the value too and was locked by the write above: it isn't one of the others.
					$others['locked'] += max( 0, (int) $a['locked'] - 1 );
					if ( (int) $a['too_many'] > 0 ) {
						$others['queued'] = (bool) \MannMade\DataPipeline\Importers\Taxonomy_Mapping_Handler::schedule_heal( [ 'mode' => 'value', 'supplier_id' => $fv['supplier'], 'source_field' => $fv['field'], 'source_value' => $fv['value'], 'wc_taxonomy' => $taxonomy, 'term_id' => $term ] );
					}
				}
			}
		}

		// Reverb refused the listing for want of a brand: try it again now rather than at the next sweep.
		$reverb = false;
		if ( $taxonomy === 'product_brand' && get_post_meta( $id, 'reverb_last_sync_error_code', true ) === 'missing_make' && function_exists( 'mmi_reverb_schedule_async_sync' ) ) {
			mmi_reverb_schedule_async_sync( $id, 'brand_set_in_workbench' );
			$reverb = true;
		}

		$names = array_map( static fn( $t ) => html_entity_decode( (string) get_term( $t, $taxonomy )->name, ENT_QUOTES, 'UTF-8' ), $ids );
		$msg   = sprintf( $taxonomy === 'product_brand' ? '%1$s brand %2$s.' : '%1$s %2$s.', $made ? 'Created and set' : 'Set', implode( ', ', $names ) );
		if ( $mapped ) {
			$msg .= ' ' . sprintf( 'Mapped %s to it', implode( ' and ', $mapped ) );
			$msg .= $others['updated'] ? sprintf( _n( '; %d other product got it too', '; %d other products got it too', $others['updated'], 'mmi-data-pipeline' ), $others['updated'] ) : '';
			$msg .= $others['locked'] ? sprintf( '; %d locked product%s kept %s own', $others['locked'], $others['locked'] === 1 ? '' : 's', $others['locked'] === 1 ? 'its' : 'their' ) : '';
			$msg .= $others['queued'] ? '; the rest are updated in the background' : '';
			$msg .= '.';
		}
		if ( $reverb ) {
			$msg .= ' Reverb will try listing it again.';
		}
		return [ 'message' => $msg, 'term_ids' => $ids, 'created' => $made, 'others' => $others ];
	}

	/** A new brand: name, optional slug, description, logo (an image from the media library) and website. */
	private static function create_brand( array $in ) {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return new \WP_Error( 'mmpt_cap', __( 'You can’t create brands.', 'mmi-data-pipeline' ), [ 'status' => 403 ] );
		}
		$name = trim( sanitize_text_field( (string) $in['name'] ) );
		$same = self::similar_brands( [ $name ] );
		if ( $same && $same[0]['score'] === 100 ) {
			return new \WP_Error( 'mmpt_exists', sprintf( __( 'A brand named “%s” already exists. Choose it instead.', 'mmi-data-pipeline' ), $same[0]['name'] ), [ 'status' => 409, 'term_id' => $same[0]['id'] ] );
		}
		$logo = absint( $in['logo_id'] ?? 0 );
		if ( $logo && ! wp_attachment_is_image( $logo ) ) {
			return new \WP_Error( 'mmpt_bad', __( 'The logo must be an image from the media library.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		$site = trim( (string) ( $in['website'] ?? '' ) );
		if ( $site !== '' && ! wp_http_validate_url( esc_url_raw( $site ) ) ) {
			return new \WP_Error( 'mmpt_bad', __( 'The website isn’t a valid address (https://…).', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		$args = [ 'description' => sanitize_textarea_field( (string) ( $in['description'] ?? '' ) ) ];
		$slug = sanitize_title( (string) ( $in['slug'] ?? '' ) );
		if ( $slug !== '' ) {
			$args['slug'] = $slug;
		}
		$t = wp_insert_term( $name, 'product_brand', $args );
		if ( is_wp_error( $t ) ) {
			return new \WP_Error( 'mmpt_bad', $t->get_error_message(), [ 'status' => 400 ] );
		}
		$term_id = (int) $t['term_id'];
		if ( $logo ) {
			update_term_meta( $term_id, 'thumbnail_id', $logo ); // WooCommerce Brands' logo
		}
		if ( $site !== '' && class_exists( '\MMI_Brand_Website' ) ) {
			\MMI_Brand_Website::set( $term_id, esc_url_raw( $site ) );
		}
		Module::info( 'Brand created from the Workbench', [ 'term' => $term_id, 'name' => $name, 'logo' => $logo, 'website' => $site, 'by' => get_current_user_id() ] );
		return $term_id;
	}

	/** A new category under a parent (0 = top level). */
	private static function create_category( array $in ) {
		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return new \WP_Error( 'mmpt_cap', __( 'You can’t create categories.', 'mmi-data-pipeline' ), [ 'status' => 403 ] );
		}
		$parent = absint( $in['parent'] ?? 0 );
		if ( $parent && ! term_exists( $parent, 'product_cat' ) ) {
			return new \WP_Error( 'mmpt_bad', __( 'That parent category no longer exists.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		$t = wp_insert_term( trim( sanitize_text_field( (string) $in['name'] ) ), 'product_cat', [ 'parent' => $parent ] );
		if ( is_wp_error( $t ) ) {
			return new \WP_Error( 'mmpt_bad', $t->get_error_message(), [ 'status' => 400 ] );
		}
		Module::info( 'Category created from the Workbench', [ 'term' => (int) $t['term_id'], 'parent' => $parent, 'by' => get_current_user_id() ] );
		return (int) $t['term_id'];
	}

	private static function fix_sku( int $id, string $sku ) {
		$sku     = trim( wc_clean( $sku ) );
		$product = wc_get_product( $id );
		if ( ! $product || $sku === '' ) {
			return new \WP_Error( 'mmpt_bad', __( 'Type a SKU.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		$was = (string) $product->get_sku( 'edit' );
		try {
			$product->set_sku( $sku ); // refuses a SKU another product has
			$product->save();
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error( 'mmpt_bad', $e->getMessage(), [ 'status' => 400 ] );
		}
		Write::logged( $id, '_sku', $was === '' ? null : $was, 'product-issue-fixes' );
		return [ 'message' => sprintf( 'SKU set to %s.', $sku ) ];
	}

	/** The descriptor phrase, picked word for word from one of the product's copy blocks (Descriptions::set_manual()). */
	private static function fix_describe( int $id, string $phrase, int $block ) {
		$in = Descriptions::inputs( [ $id ] )[ $id ] ?? null;
		$b  = $in['blocks'][ $block ] ?? null;
		if ( ! $b || trim( $phrase ) === '' ) {
			return new \WP_Error( 'mmpt_bad', __( 'Pick the words from one of the texts shown.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		$res = Descriptions::set_manual( [ $id ], (string) ( $b['url'] ?? '' ), (string) $b['text'], $phrase, (string) $b['type'] );
		if ( ! in_array( $id, $res['set'], true ) ) {
			$why = (string) ( $res['refused'][ $id ] ?? $res['refused'][0] ?? 'refused' );
			return new \WP_Error( 'mmpt_bad', $why === 'phrase not found word-for-word in the copy' ? __( 'Those words aren’t in that text word for word. Copy them from it.', 'mmi-data-pipeline' ) : ( $why === 'guard refuses the phrase for this title' ? __( 'That phrase doesn’t describe this product well enough (too long, or it only repeats the name).', 'mmi-data-pipeline' ) : $why ), [ 'status' => 400 ] );
		}
		return [ 'message' => sprintf( 'Description set to “%s”.', Descriptions::strip_article( trim( $phrase ) ) ) ];
	}

	/** The featured picture (one) or gallery pictures (added to the gallery), chosen in the media library. */
	private static function fix_pictures( int $id, string $kind, array $ids ) {
		$product = wc_get_product( $id );
		$ids     = array_values( array_filter( $ids, 'wp_attachment_is_image' ) );
		if ( ! $product || ! $ids ) {
			return new \WP_Error( 'mmpt_bad', __( 'Choose a picture from the media library.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		if ( $kind === 'image' ) {
			$was = (string) $product->get_image_id( 'edit' );
			$product->set_image_id( $ids[0] );
			$product->save();
			Write::logged( $id, '_thumbnail_id', $was === '' || $was === '0' ? null : $was, 'product-issue-fixes' );
			return [ 'message' => 'Featured picture set.' ];
		}
		$was  = array_map( 'intval', $product->get_gallery_image_ids( 'edit' ) );
		$next = array_values( array_unique( array_merge( $was, array_diff( $ids, [ (int) $product->get_image_id( 'edit' ) ] ) ) ) );
		$product->set_gallery_image_ids( $next );
		$product->save();
		Write::logged( $id, '_product_image_gallery', $was ? implode( ',', $was ) : null, 'product-issue-fixes' );
		$n = count( $next ) - count( $was );
		return [ 'message' => sprintf( _n( '%d picture added to the gallery.', '%d pictures added to the gallery.', $n, 'mmi-data-pipeline' ), $n ) ];
	}

	/** Weight, dimensions and GTIN, through WooCommerce (its lookup table and GTIN uniqueness check stay right). */
	private static function fix_props( int $id, array $in ) {
		$product = wc_get_product( $id );
		if ( ! $product ) {
			return new \WP_Error( 'mmpt_bad', __( 'Unknown product.', 'mmi-data-pipeline' ), [ 'status' => 404 ] );
		}
		$set  = [];
		$keys = [ 'weight' => '_weight', 'length' => '_length', 'width' => '_width', 'height' => '_height' ];
		try {
			foreach ( $keys as $prop => $meta ) {
				if ( ! isset( $in[ $prop ] ) || trim( (string) $in[ $prop ] ) === '' ) {
					continue;
				}
				$v = wc_format_decimal( (string) $in[ $prop ] );
				if ( $v === '' || (float) $v <= 0 ) {
					return new \WP_Error( 'mmpt_bad', sprintf( __( '%s must be a number above 0.', 'mmi-data-pipeline' ), ucfirst( $prop ) ), [ 'status' => 400 ] );
				}
				$set[ $meta ] = [ (string) $product->{'get_' . $prop}( 'edit' ), $prop ];
				$product->{'set_' . $prop}( $v );
			}
			if ( isset( $in['gtin'] ) && trim( (string) $in['gtin'] ) !== '' && method_exists( $product, 'set_global_unique_id' ) ) {
				$set['_global_unique_id'] = [ (string) $product->get_global_unique_id( 'edit' ), 'GTIN' ];
				$product->set_global_unique_id( wc_clean( (string) $in['gtin'] ) ); // refuses one another product has
			}
			if ( ! $set ) {
				return new \WP_Error( 'mmpt_bad', __( 'Fill in at least one value.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
			}
			$product->save();
		} catch ( \WC_Data_Exception $e ) {
			return new \WP_Error( 'mmpt_bad', $e->getMessage(), [ 'status' => 400 ] );
		}
		foreach ( $set as $meta => [ $was ] ) {
			Write::logged( $id, $meta, $was === '' ? null : $was, 'product-issue-fixes' );
		}
		return [ 'message' => ucfirst( implode( ', ', array_column( $set, 1 ) ) ) . ' saved.' ];
	}

	/** One-click fixes. Only run when this product's issues offer it. */
	private static function fix_action( int $id, string $action ) {
		$row     = Store::get( $id );
		$offered = [];
		foreach ( $row ? self::for_row( $row, Store::missing_for( [ $id ] )[ $id ] ?? [] ) : [] as $issue ) {
			foreach ( $issue['fixes'] as $f ) {
				if ( $f['kind'] === 'action' ) {
					$offered[] = (string) $f['action'];
				}
			}
		}
		if ( ! in_array( $action, $offered, true ) ) {
			return new \WP_Error( 'mmpt_bad', __( 'That fix isn’t offered for this product any more. Reload the list.', 'mmi-data-pipeline' ), [ 'status' => 409 ] );
		}
		if ( $action === 'virtual:yes' || $action === 'virtual:no' ) {
			$product = wc_get_product( $id );
			if ( ! $product ) {
				return new \WP_Error( 'mmpt_bad', __( 'Unknown product.', 'mmi-data-pipeline' ), [ 'status' => 404 ] );
			}
			$was = $product->get_virtual( 'edit' ) ? 'yes' : 'no';
			$product->set_virtual( $action === 'virtual:yes' );
			$product->save();
			Write::logged( $id, '_virtual', $was, 'product-issue-fixes' );
			return [ 'message' => $action === 'virtual:yes' ? 'Marked as a download (virtual: nothing ships).' : 'Marked as a product that ships (not virtual).' ];
		}
		if ( preg_match( '/^add_cat:(\d+)$/', $action, $m ) ) {
			$term = get_term( (int) $m[1], 'product_cat' );
			if ( ! $term || is_wp_error( $term ) ) {
				return new \WP_Error( 'mmpt_bad', __( 'That category no longer exists.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
			}
			$set = Rest::write_terms( $id, 'product_cat', [ (int) $term->term_id ], true );
			if ( is_wp_error( $set ) ) {
				return $set;
			}
			return [ 'message' => sprintf( 'Added to %s.', html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) ) ];
		}
		return new \WP_Error( 'mmpt_bad', __( 'Unknown fix.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
	}
}
