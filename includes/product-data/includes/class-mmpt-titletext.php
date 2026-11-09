<?php
/**
 * Generic product-title text transforms (Product Data migration phase 7d).
 *
 * Moved out of the site's composer so every store's rules can use them. The
 * logic is here; the word lists are not. Each function takes the lists it
 * needs (words kept in capitals, small words, words a brand writes in
 * lowercase, the claims a title must keep), so a store's own vocabulary stays
 * with the store: in its rule pack, or in its saved settings (BasicTitleRules).
 *
 * Pure: no database reads or writes.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TitleText {

	/** Title length limit when no SEO plugin says otherwise: about what shopping results show. */
	const MAX_LENGTH = 70;

	/** Qualifiers that may share a trailing parenthetical with the one before it (tidy()). */
	const QUALIFIER_TOKEN = '/^(download|digital download|boxed|box|physical|new|used|refurbished|renewed|edu|educational|education|academic|student|teacher|institution(al)?|upgrade|update|crossgrade|subscription|renewal|perpetual|\d+-(year|month)|\d+-pack|set of \d+)$/i';

	/**
	 * Title length limit: the characters shopping results usually show. Read from mmi-seo's limits
	 * source when it is active (so it follows the search engine's guidance when an admin updates it
	 * there); otherwise $fallback.
	 */
	public static function max_length( int $fallback = self::MAX_LENGTH ): int {
		static $max = [];
		if ( ! isset( $max[ $fallback ] ) ) {
			$n                = function_exists( 'mmi_seo_limits' ) ? (int) ( mmi_seo_limits()['merchant_title']['visible_chars'] ?? 0 ) : 0;
			$max[ $fallback ] = $n > 0 ? $n : $fallback;
		}
		return $max[ $fallback ];
	}

	/**
	 * Tidy punctuation: dashes, a colon before a parenthetical, two trailing parentheticals that are
	 * both qualifiers merged into one, spacing around < and >, double spaces.
	 *
	 * @param string $qualifier_rx what counts as a qualifier token ('' = QUALIFIER_TOKEN)
	 */
	public static function tidy( string $s, string $qualifier_rx = '' ): string {
		$qualifier_rx = $qualifier_rx !== '' ? $qualifier_rx : self::QUALIFIER_TOKEN;
		$s            = preg_replace( '/\s+[–—]\s+/u', ' - ', $s );
		$s            = preg_replace( '/\s*:\s*(?=\()/u', ' ', $s );
		// "(Upgrade from X) (Download)" → one parenthetical; "(75 Ohm) (15')" on a cable stays as it is.
		$s = preg_replace_callback(
			'/\(([^()]*)\)\s*\(([^()]*)\)$/u',
			static function ( $m ) use ( $qualifier_rx ) {
				foreach ( array_map( 'trim', explode( ',', $m[2] ) ) as $t ) {
					if ( preg_match( $qualifier_rx, $t ) ) {
						return '(' . $m[1] . ', ' . $m[2] . ')';
					}
				}
				return $m[0];
			},
			$s
		);
		$s = preg_replace( '/(?<=\w)\s*([<>])\s*(?=\w)/u', ' $1 ', $s ); // "Bdl< SM" → "Bdl < SM"
		return trim( preg_replace( '/\s{2,}/u', ' ', $s ) );
	}

	/** At least four letters, all in capitals. */
	public static function is_shouting( string $s ): bool {
		$letters = preg_replace( '/[^\p{L}]+/u', '', $s );
		return mb_strlen( $letters ) >= 4 && $letters === mb_strtoupper( $letters );
	}

	/**
	 * Title-case an ALL-CAPS string; mixed-case strings are brand styling and stay.
	 * $force: change the all-caps words even when the whole string isn't shouting.
	 *
	 * @param array<string,string> $forms lowercase word → the spelling the store uses elsewhere ("drumx" → "DrumX")
	 * @param array{keep?:string[], small?:string[], minor?:string[]} $words
	 *        keep: capitalised words that stay (acronyms, codes); small: 2–3 letter words that are words, not
	 *        acronyms (lowercase); minor: words lowercased mid-title (lowercase)
	 */
	public static function deshout( string $s, bool $force = false, array $forms = [], array $words = [] ): string {
		if ( ! $force && ! self::is_shouting( $s ) ) {
			return $s;
		}
		$keep  = (array) ( $words['keep'] ?? [] );
		$small = (array) ( $words['small'] ?? [] );
		$minor = (array) ( $words['minor'] ?? [] );
		$i     = 0;
		return preg_replace_callback(
			'/[\p{L}\p{N}\'’]+/u',
			static function ( $m ) use ( &$i, $s, $forms, $keep, $small, $minor ) {
				$w = $m[0][0];
				if ( $w !== mb_strtoupper( $w ) ) {
					$i++;
					return $w; // already mixed case ("Loyalty", "SoundID"): not shouting
				}
				$lw    = mb_strtolower( $w );
				$first = $i++ === 0;
				$at    = $m[0][1];
				if ( preg_match( '/^-?\d/', substr( $s, $at + strlen( $w ) ) ) || preg_match( '/\d-?$/', substr( $s, 0, $at ) ) ) {
					return $w; // part of a model code: "VMEQ-5", "MS-20", "TB-303 BASS" stays
				}
				if ( isset( $forms[ $lw ] ) ) {
					return $forms[ $lw ]; // the store's own spelling elsewhere in the catalog
				}
				$joined = $m[0][1] > 0 && $s[ $m[0][1] - 1 ] === '-'; // "PLUG-IN" → "Plug-In", not "Plug-in"
				if ( preg_match( '/\d/', $w ) || preg_match( '/^(I{1,3}|IV|VI{0,3}|IX|XI{0,3})$/', $w ) || ! preg_match( '/[AEIOUY]/', $w ) ) {
					return $w; // model codes (4K, A76), roman numerals, vowel-less acronyms (FDBK, VSM)
				}
				if ( in_array( $w, $keep, true ) ) {
					return $w;
				}
				if ( mb_strlen( $w ) <= 3 && ! in_array( $lw, $small, true ) ) {
					return $w; // three-letter acronyms
				}
				if ( ! $first && ! $joined && in_array( $lw, $minor, true ) ) {
					return $lw;
				}
				return mb_convert_case( $lw, MB_CASE_TITLE );
			},
			$s,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
	}

	/**
	 * Capitalize each all-lowercase word (letters only, not touching a digit, underscore, colon, dot,
	 * slash, apostrophe or hyphen). $small words stay lowercase unless they open the name; $keep words
	 * (a brand's own lowercase styling) always stay. Hyphenated words get sentence case: "Plug-in" →
	 * "Plug-In", unless a later part is capitalised (a brand's styling).
	 *
	 * @param string[] $keep  words kept exactly as written
	 * @param string[] $small lowercase small words
	 */
	public static function capitalize_words( string $s, array $keep = [], bool $is_name = true, array $small = [] ): string {
		if ( $s === '' ) {
			return $s;
		}
		// A parenthesised note inside the name ("(da fare pagina)") is left exactly as written.
		if ( strpos( $s, '(' ) !== false ) {
			$segs = preg_split( '/(\([^()]*\))/u', $s, -1, PREG_SPLIT_DELIM_CAPTURE ) ?: [ $s ];
			foreach ( $segs as $i => $seg ) {
				if ( $seg !== '' && strpos( $seg, '(' ) === false ) {
					$segs[ $i ] = self::capitalize_words( $seg, $keep, $is_name && $i === 0, $small ); // only the first segment opens the name
				}
			}
			return implode( '', $segs );
		}
		$s = (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}_:.\/\'’-])\p{Ll}+(?![\p{L}\p{N}_:.\/\'’-])/u',
			static function ( array $m ) use ( $keep, $is_name, $small ): string {
				$w = $m[0][0];
				if ( in_array( $w, $keep, true ) || ( ( ! $is_name || $m[0][1] > 0 ) && in_array( $w, $small, true ) ) ) {
					return $w;
				}
				return mb_strtoupper( mb_substr( $w, 0, 1 ) ) . mb_substr( $w, 1 );
			},
			$s,
			-1,
			$count,
			PREG_OFFSET_CAPTURE
		);
		return (string) preg_replace_callback(
			'/(?<![\p{L}\p{N}_:.\/\'’-])\p{Lu}\p{Ll}*(?:-\p{Ll}+)+(?![\p{L}\p{N}_:.\/\'’-])/u',
			static fn( array $m ): string => (string) preg_replace_callback( '/-(\p{Ll})/u', static fn( $c ) => '-' . mb_strtoupper( $c[1] ), $m[0] ),
			$s
		);
	}

	/**
	 * The claims guard: what $before says that $after no longer does. Each claim class whose pattern
	 * matched before and not after, and every number that appears fewer times after ("#12").
	 *
	 * @param array<string,string> $claims class => regex (a store's offer and audience words)
	 * @return string[]
	 */
	public static function lost_claims( string $before, string $after, array $claims = [] ): array {
		$lost = [];
		foreach ( $claims as $class => $rx ) {
			if ( preg_match( $rx, $before ) && ! preg_match( $rx, $after ) ) {
				$lost[] = $class;
			}
		}
		preg_match_all( '/\d+/', $before, $b );
		preg_match_all( '/\d+/', $after, $a );
		$have = array_count_values( $a[0] );
		foreach ( array_count_values( $b[0] ) as $n => $count ) {
			if ( ( $have[ $n ] ?? 0 ) < $count ) {
				$lost[] = '#' . $n;
			}
		}
		return $lost;
	}
}
