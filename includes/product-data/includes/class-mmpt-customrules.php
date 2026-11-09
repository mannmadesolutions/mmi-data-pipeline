<?php
/**
 * A store's own title rules: learned from a person's title edits, or written
 * directly (user request 2026-09-28).
 *
 * When someone edits a proposal on the Review tab, suggest() compares the
 * composed title with what they typed, word by word, and turns each change
 * into a candidate rule: a replacement ("Sub" → "Subscription"), a removal,
 * or an insertion anchored to the word before it. Each candidate reports how
 * many other open proposals it would change (this brand / every brand) with
 * a few before → after samples. The reviewer ticks the ones that match their
 * intent and saves them with the edit; saving is the approval.
 *
 * Rules can also be written directly (add(), update(); preview() shows their
 * reach first), so a store with no proposals yet can start from its own titles.
 *
 * Saved rules live in MMI_Settings (data, never code) and the engine applies
 * them to every finished title, whatever rule pack composed it (on_title(),
 * from TitleRules::propose(); rule id TitleRules::OWN_RULES), so the next
 * recomposition carries them to every matching product.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CustomRules {

	const SETTINGS_KEY = 'mmpt_custom_rules';
	const MAX_PHRASE   = 100;
	const MAX_RULES    = 500;
	const SAMPLES      = 3;

	/** Rows scanned when counting a rule's reach (the table holds one row per product). */
	const SCAN_LIMIT = 20000;

	/** Rule kinds: replace a phrase, remove it, or add words after it. */
	const KINDS = [ 'replace', 'remove', 'insert' ];

	/** @var bool|null whether the store's own rules are in use (memoized per request; reset() after a switch) */
	private static $enabled = null;

	/** The store's own rules are switched on (Rules → "Your rules" → In use). */
	public static function enabled(): bool {
		if ( self::$enabled === null ) {
			self::$enabled = (bool) ( Store::rules()[ TitleRules::OWN_RULES ]['enabled'] ?? true );
		}
		return self::$enabled;
	}

	/** Forget the memoized switch (a rule was switched on or off). */
	public static function reset(): void {
		self::$enabled = null;
	}

	/**
	 * Apply the store's own rules to a composed proposal, last, on the finished title. A context may
	 * carry the brand's rules already (`custom_rules`, read in bulk by the pack); otherwise they are read here.
	 *
	 * @param array $r   compose()'s result
	 * @param array $ctx the product's context
	 * @return array the same shape, with OWN_RULES in `rules` when one applied
	 */
	public static function on_title( array $r, array $ctx ): array {
		if ( ! self::enabled() ) {
			return $r;
		}
		$rules  = isset( $ctx['custom_rules'] ) ? (array) $ctx['custom_rules'] : self::for_brand( (string) ( $ctx['brand'] ?? '' ) );
		$custom = self::apply( (string) $r['title'], $rules );
		if ( ! $custom['applied'] ) {
			return $r;
		}
		$original     = PageName::clean( (string) ( $ctx['title'] ?? '' ) );
		$r['title']   = $custom['title'];
		$r['changed'] = $custom['title'] !== $original;
		$r['rules'][ TitleRules::OWN_RULES ] = [
			'from'       => implode( '; ', array_column( $custom['applied'], 'from' ) ),
			'to'         => implode( '; ', array_map( [ __CLASS__, 'label' ], $custom['applied'] ) ),
			'confidence' => 95,
			'source'     => 'your rule(s) ' . implode( ', ', array_column( $custom['applied'], 'id' ) ),
		];
		$r['confidence'] = min( $r['confidence'], 95 );
		if ( ! $r['changed'] ) {
			$r['rules'] = [];
		}
		return $r;
	}

	/**
	 * A rule a person wrote (not learned from an edit), checked and put in the stored shape.
	 * In: kind (KINDS), from (the words looked for), to (replace: what replaces them; insert: the words
	 * added after them; remove: ignored), brand ('' = every brand; a product_brand name) or brand_id.
	 *
	 * @return array{kind:string,from:string,to:string,brand:string}|\WP_Error
	 */
	public static function normalize( array $in ) {
		$squash = static fn( $v ) => trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $v ) ) );
		$kind   = (string) ( $in['kind'] ?? '' );
		$from   = $squash( $in['from'] ?? '' );
		$to     = $squash( $in['to'] ?? '' );
		if ( ! in_array( $kind, self::KINDS, true ) ) {
			return new \WP_Error( 'mmpt_rule_kind', __( 'Choose what the rule does: replace words, remove them, or add words after them.', 'mmi-admin' ), [ 'status' => 400 ] );
		}
		if ( $from === '' || mb_strlen( $from ) > self::MAX_PHRASE ) {
			/* translators: %d: maximum characters */
			return new \WP_Error( 'mmpt_rule_from', sprintf( __( 'Type the words the rule looks for (up to %d characters).', 'mmi-admin' ), self::MAX_PHRASE ), [ 'status' => 400 ] );
		}
		if ( $kind === 'remove' ) {
			$to = '';
		} elseif ( $to === '' || mb_strlen( $to ) > self::MAX_PHRASE ) {
			return new \WP_Error(
				'mmpt_rule_to',
				/* translators: %d: maximum characters */
				sprintf( $kind === 'insert' ? __( 'Type the words to add (up to %d characters).', 'mmi-admin' ) : __( 'Type what replaces the words (up to %d characters).', 'mmi-admin' ), self::MAX_PHRASE ),
				[ 'status' => 400 ]
			);
		} elseif ( $kind === 'insert' ) {
			$to = $from . ' ' . $to; // stored as the phrase after the change, like an insert learned from an edit
		}
		if ( $kind === 'replace' && $to === $from ) {
			return new \WP_Error( 'mmpt_rule_same', __( 'The rule would change nothing: the new words are the same as the old.', 'mmi-admin' ), [ 'status' => 400 ] );
		}
		$brand = '';
		$term  = ! empty( $in['brand_id'] ) ? get_term( (int) $in['brand_id'], 'product_brand' ) : null;
		if ( ! $term && trim( (string) ( $in['brand'] ?? '' ) ) !== '' ) {
			$term = get_term_by( 'name', trim( (string) $in['brand'] ), 'product_brand' );
			if ( ! $term ) {
				$term = get_term_by( 'name', esc_html( trim( (string) $in['brand'] ) ), 'product_brand' ); // names are stored with & as &amp;
			}
			if ( ! $term ) {
				/* translators: %s: brand name */
				return new \WP_Error( 'mmpt_rule_brand', sprintf( __( 'No brand is called "%s".', 'mmi-admin' ), (string) $in['brand'] ), [ 'status' => 400 ] );
			}
		}
		if ( $term && ! is_wp_error( $term ) ) {
			$brand = html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
		return [ 'kind' => $kind, 'from' => $from, 'to' => $to, 'brand' => $brand ];
	}

	/**
	 * What a rule would do before it is saved: how many worked products it would change, with a few
	 * before → after examples. Each product is judged on the title the engine starts from: its open
	 * proposal when it has one, otherwise its own title. Reads only.
	 *
	 * @param array $rule normalize()'s result (or a saved rule)
	 * @return array{count:int, samples:array, scanned:int}
	 */
	public static function preview( array $rule, int $samples = 5 ): array {
		global $wpdb;
		$t      = Store::table();
		$like   = '%' . $wpdb->esc_like( (string) $rule['from'] ) . '%';
		$status = "'" . implode( "','", Store::WORK_STATUSES ) . "'";
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID id, p.post_title title, pr.proposed_title proposed, pr.status pstatus
				 FROM {$wpdb->posts} p LEFT JOIN {$t} pr ON pr.product_id = p.ID
				 WHERE p.post_type = 'product' AND p.post_status IN ({$status}) AND ( p.post_title LIKE %s OR pr.proposed_title LIKE %s )
				 ORDER BY p.ID ASC LIMIT %d",
				$like,
				$like,
				self::SCAN_LIMIT
			),
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL -- $status is constants only
		$out  = [ 'count' => 0, 'samples' => [], 'scanned' => count( $rows ) ];
		$keep = array_flip( array_map( 'intval', (array) Exclusions::filter( array_map( 'intval', array_column( $rows, 'id' ) ) ) ) );
		$brand = (string) ( $rule['brand'] ?? '' );
		$names = $brand !== '' ? self::brands_of( array_keys( $keep ) ) : [];
		foreach ( $rows as $r ) {
			$id = (int) $r['id'];
			if ( ! isset( $keep[ $id ] ) || ( $brand !== '' && ! BrandNames::is( $names[ $id ] ?? '', BrandNames::norm( $brand ) ) ) ) {
				continue;
			}
			$open   = $r['proposed'] !== null && in_array( (string) $r['pstatus'], Store::OPEN_STATUSES, true );
			$before = $open ? (string) $r['proposed'] : PageName::clean( (string) $r['title'] );
			$res    = self::apply( $before, [ $rule ] );
			if ( ! $res['applied'] ) {
				continue;
			}
			$out['count']++;
			if ( count( $out['samples'] ) < $samples ) {
				$out['samples'][] = [ 'id' => $id, 'before' => $before, 'after' => $res['title'] ];
			}
		}
		return $out;
	}

	/**
	 * Save a rule a person wrote. Writing it is the approval, as saving one from an edit is.
	 * The same words for the same brand replace the older rule instead of stacking.
	 *
	 * @return array|\WP_Error the saved rule
	 */
	public static function add( array $in ) {
		$rule = self::normalize( $in );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$rules = array_values( array_filter( self::all(), static fn( $r ) => ! ( $r['from'] === $rule['from'] && strtolower( $r['brand'] ) === strtolower( $rule['brand'] ) ) ) );
		if ( count( $rules ) >= self::MAX_RULES ) {
			/* translators: %d: maximum number of rules */
			return new \WP_Error( 'mmpt_rule_max', sprintf( __( 'There are already %d rules. Remove one first.', 'mmi-admin' ), self::MAX_RULES ), [ 'status' => 400 ] );
		}
		$saved   = self::stored( substr( md5( wp_json_encode( $rule ) . microtime() ), 0, 10 ), $rule );
		$rules[] = $saved;
		\MMI_Settings::set( self::SETTINGS_KEY, $rules );
		Store::set_rule( TitleRules::OWN_RULES, true ); // writing it is the approval
		Module::info( 'Title rule written', [ 'rule' => $saved ] );
		return $saved;
	}

	/**
	 * Change a saved rule in place (same id, so proposals that name it are found again).
	 *
	 * @return array{before:array,after:array}|\WP_Error
	 */
	public static function update( string $id, array $in ) {
		$rule = self::normalize( $in );
		if ( is_wp_error( $rule ) ) {
			return $rule;
		}
		$rules = self::all();
		foreach ( $rules as $i => $r ) {
			if ( $r['id'] === $id ) {
				$rules[ $i ] = self::stored( $id, $rule, (int) ( $r['example'] ?? 0 ) );
				\MMI_Settings::set( self::SETTINGS_KEY, $rules );
				Module::info( 'Title rule changed', [ 'before' => $r, 'after' => $rules[ $i ] ] );
				return [ 'before' => $r, 'after' => $rules[ $i ] ];
			}
		}
		return new \WP_Error( 'mmpt_bad', __( 'That rule no longer exists.', 'mmi-admin' ), [ 'status' => 404 ] );
	}

	/** A rule in its stored shape (the shape save_from_edit() writes). */
	private static function stored( string $id, array $rule, int $example = 0 ): array {
		return [
			'id'      => $id,
			'kind'    => $rule['kind'],
			'from'    => $rule['from'],
			'to'      => $rule['to'],
			'brand'   => $rule['brand'],
			'by'      => get_current_user_id(),
			'at'      => current_time( 'mysql' ),
			'example' => $example,
		];
	}

	/** product id => brand name (decoded), for these products. @return array<int,string> */
	private static function brands_of( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$out = [];
		foreach ( array_chunk( $ids, 1000 ) as $chunk ) {
			$rows = $wpdb->get_results(
				"SELECT tr.object_id id, t.name FROM {$wpdb->term_relationships} tr
				 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
				 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				 WHERE tr.object_id IN (" . implode( ',', $chunk ) . ')',
				ARRAY_A
			); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
			foreach ( $rows as $r ) {
				$out[ (int) $r['id'] ] = html_entity_decode( (string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $out;
	}

	/** @return array<int,array{id:string,kind:string,from:string,to:string,brand:string,by:int,at:string,example:int}> */
	public static function all(): array {
		$rules = \MMI_Settings::get( self::SETTINGS_KEY, [] );
		return is_array( $rules ) ? array_values( array_filter( $rules, 'is_array' ) ) : [];
	}

	/** Rules that apply to a brand: its own plus every all-brands rule (memoized per request). */
	public static function for_brand( string $brand ): array {
		static $memo = null;
		if ( $memo === null ) {
			$memo = self::all();
		}
		return array_values( array_filter( $memo, static fn( $r ) => $r['brand'] === '' || BrandNames::is( $brand, BrandNames::norm( $r['brand'] ) ) ) );
	}

	/** A brand was renamed (BrandNames::after_edit()): its rules carry the new name, so the Rules tab shows it. */
	public static function rename_brand( string $old, string $new ): void {
		$rules   = self::all();
		$changed = false;
		foreach ( $rules as &$r ) {
			if ( $r['brand'] !== '' && BrandNames::norm( $r['brand'] ) === BrandNames::norm( $old ) ) {
				$r['brand'] = $new;
				$changed    = true;
			}
		}
		unset( $r );
		if ( $changed ) {
			\MMI_Settings::set( self::SETTINGS_KEY, $rules );
		}
	}

	/**
	 * Apply rules to a finished title. Pure — the composer stays DB-free.
	 *
	 * @return array{title:string,applied:array}
	 */
	public static function apply( string $title, array $rules ): array {
		$applied = [];
		foreach ( $rules as $r ) {
			// Already done (an applied title recomposed, or an insert whose words are there): never stack.
			if ( $r['kind'] !== 'remove' && preg_match( self::pattern( $r['to'] ), $title ) ) {
				continue;
			}
			$next = preg_replace( self::pattern( $r['from'] ), self::replacement( $r ), $title, 1 );
			if ( is_string( $next ) && $next !== $title ) {
				$title     = trim( preg_replace( '/\s{2,}/', ' ', $next ) );
				$applied[] = $r;
			}
		}
		return [ 'title' => $title, 'applied' => $applied ];
	}

	/**
	 * Candidate rules for one edit.
	 *
	 * @return array<int,array> each: key, kind, from, to, label, reach{brand,all}, samples{brand,all}
	 */
	public static function suggest( int $product_id, string $edited ): array {
		$row = Store::get( $product_id );
		if ( ! $row ) {
			return [];
		}
		$base  = (string) $row['proposed_title'];
		$brand = (string) $row['brand'];
		$out   = [];
		foreach ( self::hunks( self::words( $base ), self::words( $edited ) ) as $h ) {
			$rule = self::rule_from_hunk( $h );
			if ( ! $rule || mb_strlen( $rule['from'] ) > self::MAX_PHRASE || mb_strlen( $rule['to'] ) > self::MAX_PHRASE ) {
				continue;
			}
			$key = md5( $rule['kind'] . "\0" . $rule['from'] . "\0" . $rule['to'] );
			if ( isset( $out[ $key ] ) ) {
				continue;
			}
			$reach       = self::reach( $rule, $brand, $product_id );
			$out[ $key ] = $rule + [
				'key'   => $key,
				'brand' => $brand,
				'label' => self::label( $rule ),
				'reach' => [ 'brand' => $reach['brand']['count'], 'all' => $reach['all']['count'] ],
				'samples' => [ 'brand' => $reach['brand']['samples'], 'all' => $reach['all']['samples'] ],
			];
		}
		return array_values( $out );
	}

	/**
	 * Save rules the reviewer ticked. Each must be one suggest() produces for this edit —
	 * the client can't invent arbitrary rules.
	 *
	 * @param array $picked [{key, scope: brand|all}]
	 * @return array saved rules
	 */
	public static function save_from_edit( int $product_id, string $edited, array $picked ): array {
		$by_key = [];
		foreach ( self::suggest( $product_id, $edited ) as $s ) {
			$by_key[ $s['key'] ] = $s;
		}
		$rules = self::all();
		$saved = [];
		foreach ( $picked as $p ) {
			$s = $by_key[ (string) ( $p['key'] ?? '' ) ] ?? null;
			if ( ! $s || count( $rules ) >= self::MAX_RULES ) {
				continue;
			}
			$brand = ( $p['scope'] ?? 'brand' ) === 'all' ? '' : $s['brand'];
			$rule  = [
				'id'      => substr( md5( $s['key'] . $brand . microtime() ), 0, 10 ),
				'kind'    => $s['kind'],
				'from'    => $s['from'],
				'to'      => $s['to'],
				'brand'   => $brand,
				'by'      => get_current_user_id(),
				'at'      => current_time( 'mysql' ),
				'example' => $product_id,
			];
			// Same change for the same scope replaces the older copy instead of stacking.
			$rules   = array_values( array_filter( $rules, static fn( $r ) => ! ( $r['from'] === $rule['from'] && strtolower( $r['brand'] ) === strtolower( $brand ) ) ) );
			$rules[] = $rule;
			$saved[] = $rule;
		}
		if ( $saved ) {
			\MMI_Settings::set( self::SETTINGS_KEY, $rules );
			Store::set_rule( TitleRules::OWN_RULES, true ); // saving from the edit is the approval
			Module::info( 'Custom title rules saved from an edit', [ 'product' => $product_id, 'rules' => $saved ] );
		}
		return $saved;
	}

	/**
	 * Products a just-saved rule could change: open proposals and published
	 * product titles containing its phrase (the brand scope is left to the
	 * composer, which applies it; recomposing a product it skips is harmless).
	 *
	 * @param array $rules saved rules (save_from_edit()'s return)
	 * @return int[]
	 */
	public static function candidate_ids( array $rules ): array {
		global $wpdb;
		$t   = Store::table();
		$ids = [];
		foreach ( $rules as $rule ) {
			$like = '%' . $wpdb->esc_like( (string) $rule['from'] ) . '%';
			if ( $like === '%%' ) {
				continue;
			}
			$ids = array_merge(
				$ids,
				$wpdb->get_col( $wpdb->prepare( "SELECT product_id FROM {$t} WHERE status IN ('proposed','accepted','stale','error') AND ( proposed_title LIKE %s OR current_title LIKE %s ) LIMIT %d", $like, $like, self::SCAN_LIMIT ) ),
				$wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' AND post_title LIKE %s LIMIT %d", $like, self::SCAN_LIMIT ) )
			);
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public static function delete( string $id ): bool {
		$rules = self::all();
		$keep  = array_values( array_filter( $rules, static fn( $r ) => $r['id'] !== $id ) );
		if ( count( $keep ) === count( $rules ) ) {
			return false;
		}
		\MMI_Settings::set( self::SETTINGS_KEY, $keep );
		Module::info( 'Custom title rule removed', [ 'id' => $id ] );
		return true;
	}

	public static function label( array $r ): string {
		if ( $r['kind'] === 'remove' ) {
			return sprintf( 'Remove "%s"', $r['from'] );
		}
		if ( $r['kind'] === 'insert' ) {
			return sprintf( 'After "%s", add "%s"', $r['from'], trim( mb_substr( $r['to'], mb_strlen( $r['from'] ) ) ) );
		}
		return sprintf( '"%s" → "%s"', $r['from'], $r['to'] );
	}

	/* ── internals ─────────────────────────────────────────────────────── */

	/** @return string[] whitespace-separated words, punctuation kept on the word */
	private static function words( string $s ): array {
		return array_values( array_filter( preg_split( '/\s+/u', trim( $s ) ), 'strlen' ) );
	}

	/**
	 * Word-level LCS diff → contiguous change hunks with their neighbours.
	 *
	 * @return array<int,array{del:string[],ins:string[],prev:?string,next:?string}>
	 */
	private static function hunks( array $a, array $b ): array {
		$n  = count( $a );
		$m  = count( $b );
		$dp = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				$dp[ $i ][ $j ] = $a[ $i ] === $b[ $j ] ? $dp[ $i + 1 ][ $j + 1 ] + 1 : max( $dp[ $i + 1 ][ $j ], $dp[ $i ][ $j + 1 ] );
			}
		}
		$hunks = [];
		$cur   = null;
		$prev  = null;
		$i     = 0;
		$j     = 0;
		$close = static function () use ( &$cur, &$hunks ) {
			if ( $cur && ( $cur['del'] || $cur['ins'] ) ) {
				$hunks[] = $cur;
			}
			$cur = null;
		};
		while ( $i < $n || $j < $m ) {
			if ( $i < $n && $j < $m && $a[ $i ] === $b[ $j ] ) {
				if ( $cur ) {
					$cur['next'] = $a[ $i ];
					$close();
				}
				$prev = $a[ $i ];
				$i++;
				$j++;
				continue;
			}
			$cur = $cur ?? [ 'del' => [], 'ins' => [], 'prev' => $prev, 'next' => null ];
			if ( $j < $m && ( $i >= $n || $dp[ $i ][ $j + 1 ] >= $dp[ $i + 1 ][ $j ] ) ) {
				$cur['ins'][] = $b[ $j++ ];
			} else {
				$cur['del'][] = $a[ $i++ ];
			}
		}
		$close();
		return $hunks;
	}

	private static function rule_from_hunk( array $h ): ?array {
		$from = implode( ' ', $h['del'] );
		$to   = implode( ' ', $h['ins'] );
		if ( $from !== '' ) {
			return [ 'kind' => $to === '' ? 'remove' : 'replace', 'from' => $from, 'to' => $to ];
		}
		// Pure insertion: anchor it to the word before (or, at the very start, the word after).
		if ( $h['prev'] !== null ) {
			return [ 'kind' => 'insert', 'from' => $h['prev'], 'to' => $h['prev'] . ' ' . $to ];
		}
		if ( $h['next'] !== null ) {
			return [ 'kind' => 'replace', 'from' => $h['next'], 'to' => $to . ' ' . $h['next'] ];
		}
		return null;
	}

	/** Whole-phrase match: no letter/digit glued on either side of a word-character edge. */
	private static function pattern( string $from ): string {
		$lead  = preg_match( '/^[\p{L}\p{N}]/u', $from ) ? '(?<![\p{L}\p{N}])' : '';
		$trail = preg_match( '/[\p{L}\p{N}]$/u', $from ) ? '(?![\p{L}\p{N}])' : '';
		return '/' . $lead . preg_quote( $from, '/' ) . $trail . '/u';
	}

	private static function replacement( array $r ): string {
		return str_replace( [ '\\', '$' ], [ '\\\\', '\\$' ], $r['to'] );
	}

	/**
	 * How many other open proposals a rule would change, in the edited product's brand and
	 * across every brand, with a few samples of each.
	 */
	private static function reach( array $rule, string $brand, int $except ): array {
		global $wpdb;
		$t    = Store::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT product_id, brand, proposed_title FROM {$t}
				 WHERE status IN ('proposed','accepted','stale','error') AND product_id <> %d AND proposed_title LIKE %s
				 LIMIT %d",
				$except,
				'%' . $wpdb->esc_like( $rule['from'] ) . '%',
				self::SCAN_LIMIT
			),
			ARRAY_A
		);
		$out = [ 'brand' => [ 'count' => 0, 'samples' => [] ], 'all' => [ 'count' => 0, 'samples' => [] ] ];
		$b   = strtolower( $brand );
		foreach ( $rows as $r ) {
			$res = self::apply( (string) $r['proposed_title'], [ $rule ] );
			if ( ! $res['applied'] ) {
				continue;
			}
			$sample = [ 'id' => (int) $r['product_id'], 'before' => $r['proposed_title'], 'after' => $res['title'] ];
			foreach ( strtolower( (string) $r['brand'] ) === $b ? [ 'brand', 'all' ] : [ 'all' ] as $scope ) {
				$out[ $scope ]['count']++;
				if ( count( $out[ $scope ]['samples'] ) < self::SAMPLES ) {
					$out[ $scope ]['samples'][] = $sample;
				}
			}
		}
		return $out;
	}
}
