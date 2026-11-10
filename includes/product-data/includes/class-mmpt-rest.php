<?php
/**
 * The review API (mmpt/v1): title proposals and their writer, description and picture proposals, a store's own title
 * rules, exclusions and settings. The Product Workbench's views call it. Every route requires manage_woocommerce;
 * the wp_rest nonce is checked by core. A store's own extra routes (this site: purchase advisories) are its own class.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rest {

	const NS  = 'mmpt/v1';
	const CAP = 'manage_woocommerce';

	/** Review decisions a person can make on an open row. */
	const DECISIONS = [ 'accept' => 'accepted', 'reject' => 'rejected', 'reset' => 'proposed' ];

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		$admin = static fn() => current_user_can( self::CAP );
		$id    = [ 'id' => [ 'validate_callback' => static fn( $v ) => is_numeric( $v ) && (int) $v > 0 ] ];

		register_rest_route( self::NS, '/proposals', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'list' ] ] );
		register_rest_route( self::NS, '/proposals/rows', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'rows' ] ] );
		register_rest_route( self::NS, '/summary', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( Store::summary() ) ] );
		register_rest_route( self::NS, '/decide', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'decide' ] ] );
		register_rest_route( self::NS, '/proposals/(?P<id>\d+)/edit', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $id, 'callback' => [ __CLASS__, 'edit' ] ] );
		register_rest_route( self::NS, '/proposals/(?P<id>\d+)/apply', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $id, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( Applier::apply_one( (int) $r['id'], 'manual' ) ) ] );
		register_rest_route( self::NS, '/proposals/(?P<id>\d+)/revert', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $id, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( Applier::revert( (int) $r['id'] ) ) ] );
		register_rest_route( self::NS, '/rules', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'rule' ] ] );
		register_rest_route( self::NS, '/settings', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'settings' ] ] );
		register_rest_route( self::NS, '/generate', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( [ 'queued' => Generator::dispatch() ] ) ] );
		$kind = [ 'id' => $id['id'], 'kind' => [ 'validate_callback' => static fn( $v ) => in_array( $v, Enrichment::KINDS, true ) ] ];
		register_rest_route( self::NS, '/enrich', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'enrich_list' ] ] );
		register_rest_route( self::NS, '/enrich/summary', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( Enrichment::summary() ) ] );
		register_rest_route( self::NS, '/enrich/decide', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'enrich_decide' ] ] );
		register_rest_route( self::NS, '/enrich/(?P<id>\d+)/(?P<kind>content|image)/apply', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $kind, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( Enrichment::apply_one( (int) $r['id'], (string) $r['kind'] ) ) ] );
		register_rest_route( self::NS, '/enrich/(?P<id>\d+)/image/choose', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $id, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( Enrichment::choose( (int) $r['id'], (array) $r->get_param( 'images' ) ) ) ] );
		register_rest_route( self::NS, '/enrich/(?P<id>\d+)/(?P<kind>content|image)/revert', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $kind, 'callback' => static fn( \WP_REST_Request $r ) => rest_ensure_response( Enrichment::revert( (int) $r['id'], (string) $r['kind'] ) ) ] );
		register_rest_route( self::NS, '/enrich/rules', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => static function ( \WP_REST_Request $r ) {
			$rule     = sanitize_text_field( (string) $r->get_param( 'rule' ) );
			$approved = rest_sanitize_boolean( $r->get_param( 'approved' ) );
			Enrichment::set_rule( $rule, $approved );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'settings.update', [ 'object_type' => 'product_content_rules', 'details' => [ 'changed' => [ $rule ], 'approved' => $approved ] ] );
			}
			if ( $approved ) {
				Enrichment::dispatch();
			}
			return rest_ensure_response( Enrichment::summary() );
		} ] );
		register_rest_route( self::NS, '/enrich/settings', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => static function ( \WP_REST_Request $r ) {
			$in = [];
			if ( $r->has_param( 'enabled' ) ) {
				$in['enabled'] = rest_sanitize_boolean( $r->get_param( 'enabled' ) );
			}
			$s = Enrichment::save_settings( $in );
			Module::info( 'Enrichment apply settings saved', $s + [ 'user' => get_current_user_id() ] );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'settings.update', [ 'object_type' => 'product_content_apply', 'details' => [ 'changed' => array_keys( $in ) ] ] );
			}
			if ( $s['enabled'] ) {
				Enrichment::dispatch();
			}
			return rest_ensure_response( Enrichment::summary() );
		} ] );
		register_rest_route( self::NS, '/exclusions', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( Exclusions::summary() ) ] );
		register_rest_route(
			self::NS,
			'/exclusions',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					// Products may be typed as an ID or a SKU; unknown ones are reported, not saved.
					$products = [];
					$unknown  = [];
					foreach ( (array) $r->get_param( 'products' ) as $p ) {
						$id = Exclusions::resolve_product( sanitize_text_field( (string) $p ) );
						if ( $id ) {
							$products[] = $id;
						} else {
							$unknown[] = sanitize_text_field( (string) $p );
						}
					}
					$result = Exclusions::save( array_map( 'intval', (array) $r->get_param( 'brands' ) ), $products );
					return rest_ensure_response( $result + [ 'unknown' => $unknown, 'summary' => Exclusions::summary() ] );
				},
			]
		);
		// Rules learned from a person's own edits (CustomRules): suggestions while typing, the saved list, removal.
		register_rest_route(
			self::NS,
			'/proposals/(?P<id>\d+)/suggest-rules',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $id,
				'callback'            => static fn( \WP_REST_Request $r ) => rest_ensure_response( [ 'suggestions' => CustomRules::suggest( (int) $r['id'], self::clean_title( $r->get_param( 'title' ) ) ) ] ),
			]
		);
		register_rest_route( self::NS, '/custom-rules', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'custom_rules' ] ] );
		register_rest_route(
			self::NS,
			'/custom-rules/delete',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'callback'            => static function ( \WP_REST_Request $r ) {
					global $wpdb;
					$rule_id = sanitize_key( (string) $r->get_param( 'id' ) );
					// Proposals built with the rule name its id in their rule detail
					// ("your rule(s) 620e30f387"). They are recomposed without it before
					// this request returns, not left to the next generation job.
					$t     = Store::table();
					$using = $rule_id === '' ? [] : array_map(
						'intval',
						$wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM {$t} WHERE detail LIKE %s", '%' . $wpdb->esc_like( $rule_id ) . '%' ) )
					);
					if ( ! CustomRules::delete( $rule_id ) ) {
						return new \WP_Error( 'mmpt_bad', __( 'That rule no longer exists.', 'mmi-data-pipeline' ), [ 'status' => 404 ] );
					}
					$recomposed = $using ? Generator::recompose_now( $using ) : null;
					Generator::dispatch(); // a full run still refreshes duplicates and advisories
					$response = self::custom_rules();
					$response->set_data( $response->get_data() + [ 'recomposed' => $recomposed ] );
					return $response;
				},
			]
		);
		// Rules written directly (CustomRules::add/update), with their reach shown first (preview). Writing one
		// recomposes the products it reaches now, like a rule saved from an edit.
		$rule_args = [
			'kind'     => [ 'type' => 'string', 'required' => true ],
			'from'     => [ 'type' => 'string', 'required' => true ],
			'to'       => [ 'type' => 'string', 'default' => '' ],
			'brand'    => [ 'type' => 'string', 'default' => '' ],
			'brand_id' => [ 'type' => 'integer', 'default' => 0 ],
			'case'       => [ 'type' => 'string', 'default' => '' ],
			'categories' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ], 'default' => [] ],
			'suppliers'  => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'default' => [] ],
		];
		$rule_in   = static fn( \WP_REST_Request $r ) => [ 'kind' => (string) $r['kind'], 'from' => (string) $r['from'], 'to' => (string) $r['to'], 'brand' => (string) $r['brand'], 'brand_id' => (int) $r['brand_id'], 'case' => (string) $r['case'], 'categories' => (array) $r['categories'], 'suppliers' => (array) $r['suppliers'] ];
		register_rest_route(
			self::NS,
			'/custom-rules/order',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => [ 'ids' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ], 'required' => true ] ],
				'callback'            => static function ( \WP_REST_Request $r ) {
					CustomRules::reorder( array_map( 'sanitize_key', (array) $r['ids'] ) );
					Generator::dispatch(); // order changes what each rule sees: a full run recomposes
					return self::custom_rules();
				},
			]
		);
		register_rest_route( self::NS, '/suppliers', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( [ 'suppliers' => CustomRules::suppliers() ] ) ] );
		register_rest_route(
			self::NS,
			'/custom-rules/preview',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $rule_args,
				'callback'            => static function ( \WP_REST_Request $r ) use ( $rule_in ) {
					$rule = CustomRules::normalize( $rule_in( $r ) );
					return is_wp_error( $rule ) ? $rule : rest_ensure_response( [ 'rule' => $rule, 'label' => CustomRules::label( $rule ) ] + CustomRules::preview( $rule ) );
				},
			]
		);
		register_rest_route(
			self::NS,
			'/custom-rules/add',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $rule_args,
				'callback'            => static function ( \WP_REST_Request $r ) use ( $rule_in ) {
					$saved = CustomRules::add( $rule_in( $r ) );
					if ( is_wp_error( $saved ) ) {
						return $saved;
					}
					$recomposed = Generator::recompose_now( CustomRules::candidate_ids( [ $saved ] ) );
					Generator::dispatch(); // a full run still refreshes duplicates and the pack's follow-up work
					$response = self::custom_rules();
					$response->set_data( $response->get_data() + [ 'saved' => $saved, 'recomposed' => $recomposed ] );
					return $response;
				},
			]
		);
		register_rest_route(
			self::NS,
			'/custom-rules/update',
			[
				'methods'             => 'POST',
				'permission_callback' => $admin,
				'args'                => $rule_args + [ 'id' => [ 'type' => 'string', 'required' => true ] ],
				'callback'            => static function ( \WP_REST_Request $r ) use ( $rule_in ) {
					global $wpdb;
					$rule_id = sanitize_key( (string) $r['id'] );
					$t       = Store::table();
					$using   = $rule_id === '' ? [] : array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM {$t} WHERE detail LIKE %s", '%' . $wpdb->esc_like( $rule_id ) . '%' ) ) );
					$changed = CustomRules::update( $rule_id, $rule_in( $r ) );
					if ( is_wp_error( $changed ) ) {
						return $changed;
					}
					// Products the old wording changed, and products the new wording reaches.
					$recomposed = Generator::recompose_now( array_merge( $using, CustomRules::candidate_ids( [ $changed['after'] ] ) ) );
					Generator::dispatch();
					$response = self::custom_rules();
					$response->set_data( $response->get_data() + [ 'saved' => $changed['after'], 'recomposed' => $recomposed ] );
					return $response;
				},
			]
		);
		// The word lists of the default rule pack (BasicTitleRules), the store's own data. Saving recomposes
		// every proposal on the next run; the lists do nothing while a store's own pack is attached.
		register_rest_route(
			self::NS,
			'/title-words',
			[
				[
					'methods'             => 'GET',
					'permission_callback' => $admin,
					'callback'            => static fn() => rest_ensure_response( [ 'words' => BasicTitleRules::words(), 'defaults' => BasicTitleRules::DEFAULT_WORDS, 'pack' => TitleRules::pack()->label(), 'in_use' => TitleRules::pack() instanceof BasicTitleRules ] ),
				],
				[
					'methods'             => 'POST',
					'permission_callback' => $admin,
					'callback'            => static function ( \WP_REST_Request $r ) {
						$in    = array_intersect_key( (array) $r->get_json_params() + (array) $r->get_body_params(), BasicTitleRules::DEFAULT_WORDS );
						$words = BasicTitleRules::save_words( $in );
						if ( TitleRules::pack() instanceof BasicTitleRules ) {
							Generator::dispatch(); // proposals follow the new lists
						}
						return rest_ensure_response( [ 'words' => $words ] );
					},
				],
			]
		);
		// Category and brand editing from the Review table: the picker's list, and a save that recomposes the title.
		register_rest_route( self::NS, '/categories', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( [ 'categories' => Store::category_tree() ] ) ] );
		register_rest_route( self::NS, '/proposals/(?P<id>\d+)/categories', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $id, 'callback' => [ __CLASS__, 'set_categories' ] ] );
		register_rest_route( self::NS, '/brands', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( [ 'brands' => Store::term_tree( 'product_brand' ) ] ) ] );
		register_rest_route( self::NS, '/proposals/(?P<id>\d+)/brands', [ 'methods' => 'POST', 'permission_callback' => $admin, 'args' => $id, 'callback' => [ __CLASS__, 'set_brands' ] ] );
		register_rest_route( self::NS, '/proposals/bulk-terms', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'bulk_terms' ] ] );
		// Writing accepted titles, driven from the Review tab's toolbar and status bar.
		register_rest_route( self::NS, '/apply-run', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( Applier::start_run() ) ] );
		register_rest_route( self::NS, '/apply-stop', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( Applier::stop_run() ) ] );
		register_rest_route( self::NS, '/apply-status', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => static fn() => rest_ensure_response( Applier::status() ) ] );
	}

	public static function list( \WP_REST_Request $r ) {
		$f = [];
		foreach ( [ 'status', 'rule', 'only_rule', 'brand', 'flag', 'missing', 'stock', 'post_status', 'q', 'orderby', 'order' ] as $k ) {
			$f[ $k ] = sanitize_text_field( (string) $r->get_param( $k ) );
		}
		foreach ( [ 'min_conf', 'max_conf', 'page', 'per_page' ] as $k ) {
			$v       = $r->get_param( $k );
			$f[ $k ] = is_numeric( $v ) ? (int) $v : '';
		}
		$res = Store::query( $f );
		_prime_post_caches( array_column( $res['rows'], 'product_id' ), false, false ); // permalinks below, one query
		$stock = Store::stock_statuses( array_column( $res['rows'], 'product_id' ) );
		$cats    = Store::row_terms( array_column( $res['rows'], 'product_id' ) );
		$missing = Store::missing_for( array_column( $res['rows'], 'product_id' ) );
		$per = max( 10, min( 200, (int) ( $f['per_page'] ?: 50 ) ) );
		return rest_ensure_response(
			[
				'rows'  => array_map( static fn( $row ) => self::shape( $row, $stock, $cats, $missing ), $res['rows'] ),
				'total' => $res['total'],
				'pages' => (int) max( 1, ceil( $res['total'] / $per ) ),
			]
		);
	}

	/**
	 * The given rows as the table renders them, so the Review tab can refresh just the rows on
	 * screen after an apply batch instead of reloading the table (user 2026-10-03).
	 */
	public static function rows( \WP_REST_Request $r ) {
		$ids = array_slice( array_values( array_unique( array_filter( array_map( 'absint', explode( ',', (string) $r->get_param( 'ids' ) ) ) ) ) ), 0, 200 );
		$got = Store::get_many( $ids );
		if ( ! $got ) {
			return rest_ensure_response( [ 'rows' => [] ] );
		}
		$ids = array_keys( $got );
		_prime_post_caches( $ids, false, false );
		$stock   = Store::stock_statuses( $ids );
		$cats    = Store::row_terms( $ids );
		$missing = Store::missing_for( $ids );
		return rest_ensure_response( [ 'rows' => array_values( array_map( static fn( $row ) => self::shape( $row, $stock, $cats, $missing ), $got ) ) ] );
	}

	public static function decide( \WP_REST_Request $r ) {
		$action = sanitize_key( (string) $r->get_param( 'action' ) );
		$ids    = array_slice( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ) ), 0, 500 );
		if ( ! isset( self::DECISIONS[ $action ] ) || ! $ids ) {
			return new \WP_Error( 'mmpt_bad', __( 'Invalid decision.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		global $wpdb;
		$in = implode( ',', $ids );
		// Only open rows change; applied/reverted history is never overwritten by a bulk click.
		// Accepting an edited row keeps it `edited`, so the person's own title is what gets written
		// (2026-10-16: bulk Accept made 17 edited rows `accepted` and the applier wrote the proposal instead).
		$n = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . Store::table() . " SET status = IF(%s = 'accepted' AND status = 'edited' AND COALESCE(edited_title, '') <> '', 'edited', %s), edited_title = IF(%s = 'proposed', NULL, edited_title), reviewed_by = %d, reviewed_at = %s
				 WHERE product_id IN ({$in}) AND status IN ('proposed','accepted','rejected','edited','stale','error')",
				self::DECISIONS[ $action ],
				self::DECISIONS[ $action ],
				self::DECISIONS[ $action ],
				get_current_user_id(),
				current_time( 'mysql' )
			)
		);
		Module::info( 'Title proposals reviewed', [ 'action' => $action, 'rows' => (int) $n ] );
		if ( $n && $action === 'accept' ) {
			Applier::dispatch(); // written right away, throttled (user 2026-10-03); a no-op while a batch is queued or applying is off
		}
		return rest_ensure_response( [ 'updated' => (int) $n ] );
	}

	public static function edit( \WP_REST_Request $r ) {
		$id    = (int) $r['id'];
		$title = self::clean_title( $r->get_param( 'title' ) );
		$row   = Store::get( $id );
		if ( ! $row || in_array( $row['status'], [ 'applied', 'reverted' ], true ) ) {
			return new \WP_Error( 'mmpt_bad', __( 'This title can no longer be edited here.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		if ( $title === '' || mb_strlen( $title ) > 250 ) {
			return new \WP_Error( 'mmpt_bad', __( 'Enter a title of 1–250 characters.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		Store::update( $id, [ 'status' => 'edited', 'edited_title' => $title, 'reviewed_by' => get_current_user_id(), 'reviewed_at' => current_time( 'mysql' ) ] );
		Applier::dispatch(); // written right away, throttled

		// Rules the reviewer ticked in the edit (CustomRules suggestions): saving them is the approval.
		$picked = [];
		foreach ( (array) $r->get_param( 'rules' ) as $p ) {
			if ( is_array( $p ) && preg_match( '/^[a-f0-9]{32}$/', (string) ( $p['key'] ?? '' ) ) ) {
				$picked[] = [ 'key' => (string) $p['key'], 'scope' => ( $p['scope'] ?? '' ) === 'all' ? 'all' : 'brand' ];
			}
		}
		$saved      = $picked ? CustomRules::save_from_edit( $id, $title, $picked ) : [];
		$recomposed = null;
		if ( $saved ) {
			// Carry the new rules to every matching product now, in this request (2026-10-03:
			// left to a background job, the Review table kept showing the old titles).
			$recomposed = Generator::recompose_now( array_diff( CustomRules::candidate_ids( $saved ), [ $id ] ) );
			Generator::dispatch(); // a full run still refreshes duplicates and advisories
		}
		return rest_ensure_response( self::shape( Store::get( $id ), Store::stock_statuses( [ $id ] ), Store::row_terms( [ $id ] ) ) + [ 'rules_saved' => count( $saved ), 'recomposed' => $recomposed ] );
	}

	/**
	 * Set a product's categories, lock them against imports (Data Pipeline Field Locks, so the
	 * next supplier import can't put the old category back), and recompose its title now.
	 */
	public static function set_categories( \WP_REST_Request $r ) {
		return self::set_terms( $r, 'product_cat' );
	}

	/** The same for brands; no brand at all is allowed (a wrong one can be taken off). */
	public static function set_brands( \WP_REST_Request $r ) {
		return self::set_terms( $r, 'product_brand' );
	}

	private static function set_terms( \WP_REST_Request $r, string $taxonomy ) {
		$id  = (int) $r['id'];
		$ids = self::term_ids( $r );
		if ( get_post_type( $id ) !== 'product' ) {
			return new \WP_Error( 'mmpt_bad', __( 'Unknown product.', 'mmi-data-pipeline' ), [ 'status' => 404 ] );
		}
		$bad = self::check_terms( $taxonomy, $ids, false );
		if ( $bad ) {
			return $bad;
		}
		$set = self::write_terms( $id, $taxonomy, $ids, false );
		if ( is_wp_error( $set ) ) {
			return $set;
		}
		$row = Generator::recompose_one( $id );
		if ( ! $row ) {
			// The recomposed title matches the live one, so (as in a full run) there's nothing left to review.
			return rest_ensure_response( [ 'id' => $id, 'removed' => true ] );
		}
		return rest_ensure_response( self::shape( $row, Store::stock_statuses( [ $id ] ), Store::row_terms( [ $id ] ) ) );
	}

	/** A typed title, whitespace-collapsed and plain text. */
	private static function clean_title( $raw ): string {
		return trim( preg_replace( '/\s+/u', ' ', sanitize_text_field( (string) $raw ) ) );
	}

	/** Saved custom rules for the Rules tab, newest first. */
	public static function custom_rules() {
		$order = array_flip( array_column( CustomRules::all(), 'id' ) );
		$rules = array_reverse( CustomRules::all() );
		return rest_ensure_response(
			[
				'rules' => array_map(
					static fn( $x ) => [
						'id'      => $x['id'],
						'label'   => CustomRules::label( $x ),
						'kind'    => $x['kind'],
						'from'    => $x['from'],
						'to'      => $x['kind'] === 'insert' ? trim( mb_substr( $x['to'], mb_strlen( $x['from'] ) ) ) : $x['to'], // as typed: the words added
						'case'       => $x['case'] ?? '',
						'categories' => $x['categories'] ?? [],
						'suppliers'  => $x['suppliers'] ?? [],
						'scope'      => CustomRules::scope_label( $x ),
						'position'   => ( $order[ $x['id'] ] ?? 0 ) + 1,
						'brand'   => $x['brand'],
						'at'      => $x['at'],
						'by'      => ( $u = get_userdata( (int) $x['by'] ) ) ? $u->display_name : '',
						'example' => (int) $x['example'],
					],
					$rules
				),
			]
		);
	}

	public static function rule( \WP_REST_Request $r ) {
		$id = sanitize_key( (string) $r->get_param( 'rule' ) );
		if ( ! isset( TitleRules::pack()->all_rules()[ $id ] ) ) { // the attached pack's rules (house style here, Basic elsewhere) and the store's own
			return new \WP_Error( 'mmpt_bad', __( 'Unknown rule.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		if ( $r->has_param( 'enabled' ) ) {
			// "In use": every proposal is recomposed now, in this request (the whole
			// catalog takes ~6 s), so the Review tab, auto-apply and the applier see
			// the change at once, not after the next daily generation.
			$enabled = rest_sanitize_boolean( $r->get_param( 'enabled' ) );
			if ( ! Store::set_rule_enabled( $id, $enabled ) ) {
				return new \WP_Error( 'mmpt_bad', __( 'This rule is always on.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
			}
			@set_time_limit( 120 );
			$stats = Generator::run( $enabled ? null : $id );
			if ( isset( $stats['skipped'] ) ) {
				Generator::dispatch(); // a run already in progress: queue another for after it
			}
			Module::info( 'Title rule ' . ( $enabled ? 'switched on' : 'switched off' ), [ 'rule' => $id, 'user' => get_current_user_id(), 'recompose' => $stats ] );
			return rest_ensure_response( Store::summary() + [ 'recompose' => $stats ] );
		}
		$approved = rest_sanitize_boolean( $r->get_param( 'approved' ) );
		Store::set_rule( $id, $approved );
		Module::info( 'Title rule ' . ( $approved ? 'approved' : 'unapproved' ), [ 'rule' => $id, 'user' => get_current_user_id() ] );
		return rest_ensure_response( Store::summary() );
	}

	public static function settings( \WP_REST_Request $r ) {
		$in = [];
		if ( $r->has_param( 'enabled' ) ) {
			$in['enabled'] = rest_sanitize_boolean( $r->get_param( 'enabled' ) );
		}
		if ( $r->has_param( 'auto' ) ) {
			$in['auto'] = rest_sanitize_boolean( $r->get_param( 'auto' ) );
		}
		foreach ( [ 'batch_size', 'min_confidence' ] as $k ) {
			if ( $r->has_param( $k ) ) {
				$in[ $k ] = absint( $r->get_param( $k ) );
			}
		}
		$s = Store::save_apply_settings( $in );
		Module::info( 'Title apply settings saved', $s + [ 'user' => get_current_user_id() ] );
		if ( $s['enabled'] ) {
			Applier::dispatch();
		}
		return rest_ensure_response( Store::summary() );
	}

	public static function enrich_list( \WP_REST_Request $r ) {
		$f   = [
			'kind'   => sanitize_key( (string) $r->get_param( 'kind' ) ),
			'status' => sanitize_key( (string) $r->get_param( 'status' ) ),
			'source' => sanitize_key( (string) $r->get_param( 'source' ) ),
			'page'   => absint( $r->get_param( 'page' ) ),
			'has_images' => sanitize_key( (string) $r->get_param( 'has_images' ) ),
			'product'    => absint( $r->get_param( 'product' ) ),
			'per_page'   => absint( $r->get_param( 'per_page' ) ),
		];
		$ids = PageList::scope_of( $r ); // a scope: brand, site, search
		if ( $ids !== null ) {
			$f['ids'] = $ids;
		}
		$res = Enrichment::query( $f );
		$res['rows'] = array_map(
			static fn( $row ) => [
				'id'         => (int) $row['product_id'],
				'kind'       => $row['kind'],
				'status'     => $row['status'],
				'source'     => $row['source'],
				'source_url' => $row['source_url'],
				'title'      => html_entity_decode( $row['post_title'], ENT_QUOTES, 'UTF-8' ),
				'current'    => mb_substr( Descriptions::plain( (string) $row['current_value'] ), 0, 300 ),
				'proposed'   => $row['kind'] === 'content' ? mb_substr( Descriptions::plain( (string) $row['proposed_value'] ), 0, 900 ) : '',
				'chars'      => mb_strlen( Descriptions::plain( (string) $row['proposed_value'] ) ),
				'images'     => $row['kind'] === 'image' ? array_slice( (array) json_decode( (string) $row['proposed_value'], true ), 0, Enrichment::MAX_CANDIDATES ) : [],
				// Picker: the product's own pictures (rows for products that have some), the pick, and AVIF waiting.
				'own'        => $row['kind'] === 'image' ? array_map( static fn( $tok ) => [ 'token' => $tok, 'url' => (string) wp_get_attachment_image_url( (int) substr( $tok, 4 ), 'medium' ) ], Enrichment::current_tokens( (string) $row['current_value'] ) ) : [],
				'chosen'     => $row['kind'] === 'image' ? array_values( (array) json_decode( (string) ( $row['chosen'] ?? '' ), true ) ) : [],
				'waiting'    => $row['kind'] === 'image' && strpos( (string) $row['detail'], 'avif_wait' ) !== false,
				'low_res'    => $row['kind'] === 'image' ? (array) ( json_decode( (string) ( $row['detail'] ?? '' ), true )['low_res'] ?? [] ) : [],
				'edit_url'   => get_edit_post_link( (int) $row['product_id'], 'raw' ),
				'view_url'   => get_permalink( (int) $row['product_id'] ),
			],
			$res['rows']
		);
		return rest_ensure_response( $res );
	}

	public static function enrich_decide( \WP_REST_Request $r ) {
		global $wpdb;
		$map    = [ 'accept' => 'accepted', 'reject' => 'rejected', 'reset' => 'proposed' ];
		$action = sanitize_key( (string) $r->get_param( 'action' ) );
		$kind   = sanitize_key( (string) $r->get_param( 'kind' ) );
		$ids    = array_slice( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ) ), 0, 500 );
		if ( ! isset( $map[ $action ] ) || ! in_array( $kind, Enrichment::KINDS, true ) || ! $ids ) {
			return new \WP_Error( 'mmpt_bad', __( 'Invalid decision.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		$n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . Enrichment::table() . ' SET status = %s, reviewed_by = %d, reviewed_at = %s WHERE kind = %s AND product_id IN (' . implode( ',', $ids ) . ") AND status IN ('proposed','accepted','rejected','stale','error')", $map[ $action ], get_current_user_id(), current_time( 'mysql' ), $kind ) );
		if ( $n && $action === 'accept' ) {
			Enrichment::dispatch(); // written right away, throttled, like titles (owner 2026-10-04); a no-op while a batch is queued or writing is off
		}
		return rest_ensure_response( [ 'updated' => (int) $n ] );
	}

	/**
	 * The Review table's bulk "Change brand" / "Change category": the same write as one row's
	 * picker (set, lock against imports, recompose), for every selected product. Mode
	 * `replace` sets exactly the chosen terms; `add` keeps each product's own and adds them.
	 */
	public static function bulk_terms( \WP_REST_Request $r ) {
		$taxonomy = [ 'cats' => 'product_cat', 'brands' => 'product_brand' ][ sanitize_key( (string) $r->get_param( 'kind' ) ) ] ?? '';
		$append   = sanitize_key( (string) $r->get_param( 'mode' ) ) === 'add';
		$products = array_slice( array_values( array_unique( array_filter( array_map( 'absint', (array) $r->get_param( 'products' ) ) ) ) ), 0, 200 );
		$ids      = self::term_ids( $r );
		if ( ! $taxonomy ) {
			return new \WP_Error( 'mmpt_bad', __( 'Unknown term type.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		if ( ! $products ) {
			return new \WP_Error( 'mmpt_bad', __( 'Select at least one product.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		$bad = self::check_terms( $taxonomy, $ids, $append );
		if ( $bad ) {
			return $bad;
		}
		$changed = [];
		$errors  = [];
		foreach ( $products as $id ) {
			$set = get_post_type( $id ) === 'product' ? self::write_terms( $id, $taxonomy, $ids, $append ) : new \WP_Error( 'mmpt_bad', __( 'Unknown product.', 'mmi-data-pipeline' ) );
			if ( is_wp_error( $set ) ) {
				$errors[ $id ] = $set->get_error_message();
				continue;
			}
			$changed[] = $id;
		}
		$rows    = [];
		$removed = [];
		foreach ( $changed as $id ) {
			$row = Generator::recompose_one( $id );
			if ( $row ) {
				$rows[ $id ] = $row;
			} else {
				$removed[] = $id;
			}
		}
		$keys    = array_keys( $rows );
		$stock   = Store::stock_statuses( $keys );
		$terms   = Store::row_terms( $keys );
		$missing = Store::missing_for( $keys );
		Module::info( 'Product terms bulk-changed from the Review table', [ 'taxonomy' => $taxonomy, 'mode' => $append ? 'add' : 'replace', 'terms' => $ids, 'products' => count( $changed ), 'errors' => count( $errors ), 'by' => get_current_user_id() ] );
		return rest_ensure_response(
			[
				'rows'    => array_values( array_map( static fn( $row ) => self::shape( $row, $stock, $terms, $missing ), $rows ) ),
				'removed' => $removed,
				'errors'  => $errors,
			]
		);
	}

	/** The `ids` param as distinct term ids. */
	private static function term_ids( \WP_REST_Request $r ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $r->get_param( 'ids' ) ) ) ) );
	}

	/** Why these terms can't be saved, or null. Adding nothing is pointless; a category is always required. */
	private static function check_terms( string $taxonomy, array $ids, bool $append ): ?\WP_Error {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'mmpt_bad', __( 'That taxonomy is not registered.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		if ( ! $ids && ( $taxonomy === 'product_cat' || $append ) ) {
			return new \WP_Error( 'mmpt_bad', $taxonomy === 'product_cat' ? __( 'Choose at least one category.', 'mmi-data-pipeline' ) : __( 'Choose at least one brand to add.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
		}
		foreach ( $ids as $term_id ) {
			if ( ! term_exists( $term_id, $taxonomy ) ) {
				return new \WP_Error( 'mmpt_bad', $taxonomy === 'product_cat' ? __( 'One of those categories no longer exists.', 'mmi-data-pipeline' ) : __( 'One of those brands no longer exists.', 'mmi-data-pipeline' ), [ 'status' => 400 ] );
			}
		}
		return null;
	}

	/** Set (or add) one product's terms, lock them against supplier imports, and log it. */
	private static function write_terms( int $id, string $taxonomy, array $ids, bool $append ) {
		$before = wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids' ] );
		$before = is_wp_error( $before ) ? [] : array_map( 'intval', $before );
		// A person's change from the Review table: written over a lock, then locked against supplier imports.
		$res = Write::field( $id, 'tax:' . $taxonomy, $append ? array_merge( $before, array_map( 'intval', $ids ) ) : $ids, 'product-terms', 'force', [ 'lock' => true ] );
		if ( $res['result'] === 'error' ) {
			return new \WP_Error( 'mmpt_terms', (string) ( $res['message'] ?? '' ), [ 'status' => 500 ] );
		}
		wc_delete_product_transients( $id );
		Module::info( $taxonomy === 'product_cat' ? 'Product categories changed from the Review table' : 'Product brands changed from the Review table', [ 'product' => $id, 'before' => $before, 'after' => wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids' ] ), 'by' => get_current_user_id() ] );
		return true;
	}

	/**
	 * The row as the review screen needs it — no internal columns it doesn't render.
	 *
	 * @param array             $row   Proposal row.
	 * @param array<int,string> $stock Store::stock_statuses() for the rows being shaped.
	 * @param array             $terms Store::row_terms() for the rows being shaped.
	 */
	public static function shape( array $row, array $stock = [], array $terms = [], ?array $missing = null ): array {
		$missing = $missing ?? Store::missing_for( [ (int) $row['product_id'] ] );
		$locked  = static fn( string $tax ) => class_exists( 'MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( (int) $row['product_id'], $tax );
		return [
			'missing'    => $missing[ (int) $row['product_id'] ] ?? [],
			'id'         => $row['product_id'],
			'status'     => $row['status'],
			'current'    => $row['current_title'],
			'proposed'   => $row['proposed_title'],
			'edited'     => $row['edited_title'],
			'original'   => $row['original_title'],
			'confidence' => $row['confidence'],
			'rules'      => $row['rules'],
			'flags'      => $row['flags'],
			'dupes'      => $row['dupes'],
			'brand'      => $row['brand'],
			'feed'       => $row['feed'],
			'stock'      => (string) ( $stock[ $row['product_id'] ] ?? '' ),
			'post_status' => (string) get_post_status( (int) $row['product_id'] ), // callers prime the post cache
			'cats'       => $terms['cats'][ $row['product_id'] ] ?? [],
			'cat_locked' => $locked( 'product_cat' ),
			'brands'     => $terms['brands'][ $row['product_id'] ] ?? [],
			'brand_locked' => $locked( 'product_brand' ),
			'price'      => (string) ( $terms['prices'][ $row['product_id'] ] ?? '' ),
			'sku'        => (string) ( $row['detail']['sku'] ?? '' ),
			'why'        => $row['detail']['rules'] ?? [],
			'renamed'    => $row['detail']['renamed'] ?? null, // {from, to}: the supplier's old and new name (Generator)
			'error'      => (string) ( $row['detail']['error'] ?? '' ),
			'applied_at' => $row['applied_at'],
			'reviewed_at' => $row['reviewed_at'],
			'edit_url'   => get_edit_post_link( $row['product_id'], 'raw' ),
			'view_url'   => get_permalink( $row['product_id'] ),
		];
	}
}
