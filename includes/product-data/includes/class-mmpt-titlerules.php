<?php
/**
 * A store's title rules, as the titles engine sees them (Product Data migration phase 7).
 *
 * The engine (Store, Generator, Applier) keeps the proposal queue: one row per
 * product whose composed title differs from its own, the review decision, the
 * throttled write and Revert. How a title is composed is not the engine's
 * business. It asks the rule pack that filter `mmi_pipeline_title_rules`
 * returns: an instance of a subclass of this class.
 *
 * This base class is the neutral pack: no rules, nothing proposed, no products
 * exempt from the duplicate-title check, no follow-up work. A store with no
 * pack attached runs the engine, stores nothing and shows an empty queue.
 *
 * A context (contexts()) needs `title`. The engine also reads, when present:
 * `brand`, `feed` (where the product's data comes from), `feed_title` (the
 * supplier's own name for it: a change reopens an applied row), and keeps
 * `sku`, `sub_category` and `digital_why` in the row's detail for the screen.
 *
 * compose() returns `changed` (bool), `title`, `confidence` (0–100), `rules`
 * (rule id => what it did), `flags` (string[]) and `parts` (for the screen).
 * The engine calls propose(), which adds the store's own rules (CustomRules)
 * on top, so a store with no pack still gets the rules its people write.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TitleRules {

	/**
	 * The engine's own rule: the store's saved title rules (CustomRules). Every store has it, whatever
	 * pack is attached; it is applied to the finished title, after the pack's rules.
	 */
	const OWN_RULES = 'custom';

	/** @var TitleRules|null the attached pack, once one answered */
	private static $pack = null;

	/** The store's rule pack, or the neutral one when none is attached. */
	public static function pack(): TitleRules {
		if ( self::$pack === null ) {
			$pack = apply_filters( 'mmi_pipeline_title_rules', null );
			if ( ! $pack instanceof self ) {
				return new self(); // not kept: a pack attached later in the request still counts
			}
			self::$pack = $pack;
		}
		return self::$pack;
	}

	/** What the screen calls this set of rules ('' = none attached). */
	public function label(): string {
		return '';
	}

	/** @return array<string,array{0:string,1:string}> rule id => [ label, description ] */
	public function rules(): array {
		return [];
	}

	/**
	 * The pack's rules plus the store's own rules (OWN_RULES), which every store has. A pack may list
	 * OWN_RULES itself to place it among its own; otherwise it comes last.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	final public function all_rules(): array {
		$rules = $this->rules();
		if ( ! isset( $rules[ self::OWN_RULES ] ) ) {
			$rules[ self::OWN_RULES ] = [ 'Your rules', 'Word changes you save: written on the Rules screen, or ticked while editing a proposed title. Applied to the finished title after every other rule.' ];
		}
		return $rules;
	}

	/** One product's proposal: the pack's composition, then the store's own rules. */
	final public function propose( array $ctx ): array {
		return CustomRules::on_title( $this->compose( $ctx ), $ctx );
	}

	/** Rule ids that can't be switched off. @return string[] */
	public function always_on(): array {
		return [];
	}

	/** A rule was switched on or off: forget anything cached about the switches. */
	public function rules_changed(): void {
	}

	/**
	 * Flags compose() can raise. `blocking` keep a proposal out of auto-apply whatever
	 * rules are approved; `shown` are counted on the screen only.
	 *
	 * @return array{blocking:string[], shown:string[]}
	 */
	public function flags(): array {
		return [ 'blocking' => [], 'shown' => [] ];
	}

	/**
	 * What compose() reads about each product.
	 *
	 * @param int[] $ids
	 * @return array<int,array> id => context
	 */
	public function contexts( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		_prime_post_caches( $ids, false, false );
		$out = [];
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post && $post->post_type === 'product' ) {
				$out[ $id ] = [ 'title' => (string) $post->post_title, 'brand' => '', 'feed' => '', 'feed_title' => '' ];
			}
		}
		return $out;
	}

	/** One product's proposal. The neutral pack proposes nothing. */
	public function compose( array $ctx ): array {
		return [
			'changed'    => false,
			'title'      => PageName::clean( (string) ( $ctx['title'] ?? '' ) ),
			'confidence' => 0,
			'rules'      => [],
			'flags'      => [],
			'parts'      => [],
		];
	}

	/** Products kept out of the duplicate-title check (expected to share another's title). @return int[] */
	public function duplicate_exempt_ids(): array {
		return [];
	}

	/** Work that follows a full run; its stats join the run's, keyed by name. @return array<string,mixed> */
	public function after_generate(): array {
		return [];
	}
}
