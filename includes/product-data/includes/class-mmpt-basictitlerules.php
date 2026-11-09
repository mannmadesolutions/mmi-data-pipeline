<?php
/**
 * The title rules every store gets when it attaches no rule pack of its own
 * (Product Data migration phase 7d): tidy punctuation, fix ALL CAPS,
 * capitalize words, and put the brand first. Each is a rule on the Rules
 * screen with its own "In use" switch and "May auto-apply" approval; none is
 * approved to start with, so the first run shows proposals to review and
 * nothing is written unreviewed.
 *
 * The logic is TitleText's; the word lists are the store's own data
 * (settings key WORDS_KEY): words kept in capitals, small words, words a
 * brand writes in lowercase. A store's own saved rules (CustomRules) are
 * applied on top by the engine, as for any pack.
 *
 * Two guards: a proposal that would lose a number the title had ("Pack 2",
 * "Vol. 3") is not made; one longer than the length limit is flagged.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BasicTitleRules extends TitleRules {

	/** Settings key of the store's word lists. */
	const WORDS_KEY = 'mmpt_title_words';

	/** The word lists a store starts with (English). */
	const DEFAULT_WORDS = [
		// Capitalised words of four or more letters that stay in capitals (shorter ones and vowel-less ones always stay).
		'keep_caps'        => [ 'MIDI', 'HDMI', 'ASIO', 'OLED', 'QLED', 'NVME', 'UEFI', 'WIFI' ],
		// Two- and three-letter words in capitals that are words, not acronyms: they are title-cased.
		'small_words'      => [ 'the', 'and', 'of', 'for', 'big', 'new', 'old', 'pop', 'hot', 'red', 'sun', 'sky', 'one', 'two', 'ten', 'six', 'my', 'in', 'on', 'to', 'at', 'by', 'up', 'out', 'off', 'all', 'man', 'boy', 'day', 'key', 'box', 'lab', 'fun', 'ice', 'jam', 'kit', 'set', 'pro', 'max', 'mini', 'go', 'air', 'pad', 'cup', 'bag', 'hat', 'mat', 'pen', 'toy' ],
		// Words lowercased in the middle of a title.
		'minor_words'      => [ 'the', 'and', 'of', 'for', 'in', 'on', 'to', 'at', 'by', 'a', 'an', 'or' ],
		// Words that stay lowercase when "Capitalize words" runs, unless they open the title.
		'lowercase_small'  => [ 'a', 'an', 'and', 'as', 'at', 'but', 'by', 'for', 'from', 'in', 'into', 'nor', 'of', 'on', 'or', 'over', 'per', 'the', 'to', 'vs', 'via', 'with', 'x', 'de', 'da', 'del', 'di', 'du', 'des', 'la', 'le', 'les', 'el', 'los', 'las', 'pour', 'para', 'con', 'et', 'und', 'für', 'von', 'der', 'die', 'das', 'mit', 'im' ],
		// Brand (lowercase) → words that brand writes in lowercase, kept as written.
		'lowercase_styled' => [],
	];

	const RULES = [
		'tidy'         => [ 'Tidy punctuation', 'Collapses double spaces, turns long dashes into " - ", drops a stray colon before "(", and merges a trailing qualifier parenthetical into the one before it.' ],
		'deshout'      => [ 'Fix ALL CAPS', 'Title-cases a title written in capitals. Model codes, roman numerals, words without vowels, three-letter acronyms and the words kept in capitals stay as they are.' ],
		'capitalize'   => [ 'Capitalize words', 'Capitalizes all-lowercase words. Small words (of, and, for…) stay lowercase unless they open the title; words a brand writes in lowercase stay.' ],
		'brand_prefix' => [ 'Brand first', 'Starts the title with the product\'s brand, as the brand is written, unless the title already names it.' ],
	];

	/** @var array<string,bool>|null rule id → "In use", read once per request */
	private $switches = null;

	public function label(): string {
		return 'Basic';
	}

	public function rules(): array {
		return self::RULES;
	}

	public function always_on(): array {
		return [ 'tidy' ];
	}

	public function rules_changed(): void {
		$this->switches = null;
	}

	public function flags(): array {
		return [ 'blocking' => [ 'over_length', 'claim_guard' ], 'shown' => [ 'brand_elsewhere' ] ];
	}

	/** The store's word lists, each falling back to DEFAULT_WORDS. */
	public static function words(): array {
		$saved = \MMI_Settings::get( self::WORDS_KEY, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$out   = [];
		foreach ( self::DEFAULT_WORDS as $k => $default ) {
			$out[ $k ] = isset( $saved[ $k ] ) && is_array( $saved[ $k ] ) ? $saved[ $k ] : $default;
		}
		return $out;
	}

	/**
	 * Save the store's word lists (only the keys given change). Lists are trimmed and de-duplicated;
	 * keep_caps is stored in capitals, the others in lowercase.
	 *
	 * @return array the lists now in force
	 */
	public static function save_words( array $in ): array {
		$saved = \MMI_Settings::get( self::WORDS_KEY, [] );
		$saved = is_array( $saved ) ? $saved : [];
		$clean = static fn( $list, $upper ) => array_values( array_unique( array_filter( array_map( static fn( $w ) => $upper ? mb_strtoupper( trim( (string) $w ) ) : mb_strtolower( trim( (string) $w ) ), (array) $list ), 'strlen' ) ) );
		foreach ( self::DEFAULT_WORDS as $k => $default ) {
			if ( ! array_key_exists( $k, $in ) ) {
				continue;
			}
			if ( $k === 'lowercase_styled' ) {
				$map = [];
				foreach ( (array) $in[ $k ] as $brand => $list ) {
					$b = mb_strtolower( trim( (string) $brand ) );
					if ( $b !== '' && ( $list = $clean( $list, false ) ) ) {
						$map[ $b ] = $list;
					}
				}
				$saved[ $k ] = $map;
			} else {
				$saved[ $k ] = $clean( $in[ $k ], $k === 'keep_caps' );
			}
		}
		\MMI_Settings::set( self::WORDS_KEY, $saved );
		return self::words();
	}

	public function contexts( array $ids ): array {
		$out = parent::contexts( $ids );
		if ( ! $out ) {
			return $out;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT tr.object_id id, t.name FROM {$wpdb->term_relationships} tr
			 JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand'
			 JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			 WHERE tr.object_id IN (" . implode( ',', array_map( 'intval', array_keys( $out ) ) ) . ')',
			ARRAY_A
		); // phpcs:ignore WordPress.DB.PreparedSQL -- integers only
		foreach ( $rows as $r ) {
			$id = (int) $r['id'];
			if ( isset( $out[ $id ] ) && $out[ $id ]['brand'] === '' ) {
				$out[ $id ]['brand'] = html_entity_decode( (string) $r['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $out;
	}

	public function compose( array $ctx ): array {
		$original = PageName::clean( (string) ( $ctx['title'] ?? '' ) );
		$brand    = trim( (string) ( $ctx['brand'] ?? '' ) );
		$words    = self::words();
		$title    = $original;
		$rules    = [];
		$flags    = [];
		$conf     = 100;
		$step     = static function ( string $id, string $next, int $confidence, string $source ) use ( &$title, &$rules, &$conf ) {
			if ( $next !== $title ) {
				$rules[ $id ] = [ 'from' => $title, 'to' => $next, 'confidence' => $confidence, 'source' => $source ];
				$conf         = min( $conf, $confidence );
				$title        = $next;
			}
		};

		$step( 'tidy', TitleText::tidy( $title ), 95, 'punctuation' );
		if ( $this->on( 'deshout' ) && TitleText::is_shouting( $title ) ) {
			$calm = TitleText::deshout( $title, false, [], [ 'keep' => $words['keep_caps'], 'small' => $words['small_words'], 'minor' => $words['minor_words'] ] );
			$step( 'deshout', $calm, preg_match( '/\b[A-Z]{4}\b/', $title ) ? 75 : 85, 'title was in capitals' );
		}
		if ( $this->on( 'capitalize' ) ) {
			$keep = array_merge( $words['lowercase_styled'][ mb_strtolower( $brand ) ] ?? [], preg_split( '/\s+/', mb_strtolower( $brand ) ) ?: [] );
			$step( 'capitalize', TitleText::capitalize_words( $title, $keep, true, $words['lowercase_small'] ), 90, 'lowercase words' );
		}
		if ( $brand !== '' && $this->on( 'brand_prefix' ) ) {
			[ $next, $elsewhere ] = self::brand_first( $title, $brand );
			if ( $elsewhere ) {
				$flags[] = 'brand_elsewhere';
			}
			$step( 'brand_prefix', $next, 90, 'brand: ' . $brand );
		}

		$changed = $title !== $original;
		if ( $changed && ( $lost = TitleText::lost_claims( $original, $title ) ) ) {
			return [ 'changed' => false, 'title' => $original, 'confidence' => 0, 'rules' => [], 'flags' => [ 'claim_guard:' . implode( '+', $lost ) ], 'parts' => [] ];
		}
		if ( mb_strlen( $title ) > TitleText::max_length() ) {
			$flags[] = 'over_length';
		}
		return [ 'changed' => $changed, 'title' => $title, 'confidence' => $changed ? $conf : 0, 'rules' => $changed ? $rules : [], 'flags' => $flags, 'parts' => [] ];
	}

	/**
	 * The title starting with the brand as the store writes it. A title that already starts with a form of
	 * the brand gets that form respelled; one that names the brand later is left alone (and reported).
	 *
	 * @return array{0:string,1:bool} [title, brand named elsewhere in it]
	 */
	public static function brand_first( string $title, string $brand ): array {
		$display = BrandNames::display( $brand );
		$forms   = array_values( array_unique( array_filter( array_merge( [ $display ], BrandNames::forms( $brand ) ), 'strlen' ) ) );
		usort( $forms, static fn( $a, $b ) => mb_strlen( $b ) <=> mb_strlen( $a ) );
		foreach ( $forms as $form ) {
			if ( preg_match( '/^' . preg_quote( $form, '/' ) . '(?=$|[\s:,\-–])/iu', $title, $m ) ) {
				return [ $m[0] === $display ? $title : $display . mb_substr( $title, mb_strlen( $m[0] ) ), false ];
			}
		}
		foreach ( $forms as $form ) {
			if ( preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $form, '/' ) . '(?![\p{L}\p{N}])/iu', $title ) ) {
				return [ $title, true ];
			}
		}
		return [ $display . ' ' . $title, false ];
	}

	/** A rule's "In use" switch (Rules screen). */
	private function on( string $id ): bool {
		if ( $this->switches === null ) {
			$this->switches = array_map( static fn( $r ) => (bool) $r['enabled'], Store::rules() );
		}
		return $this->switches[ $id ] ?? true;
	}
}
