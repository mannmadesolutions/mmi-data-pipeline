<?php
/**
 * Reading a product's system requirements from its maker's page: the
 * requirements section of a page's text, per operating system (version, CPU,
 * RAM, disk, plug-in formats, the player a library needs, licensing), and what
 * of that may be written to the product (fill_product(): only empty fields,
 * never a locked one, platforms only added to).
 *
 * Part of the Product Data module that moves to mmi-data-pipeline (phase 5a,
 * PRODUCT_DATA_MIGRATION_SCOPE.md). Moved here verbatim from Product Variants'
 * Requirements (2026-11-09), which keeps the per-family parts (capture, apply)
 * and extends this class, so Requirements::extract() and the rest still work.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PageRequirements {

	const SECTION = '/\b(system requirements|system compatibility|requirements and compatibility|minimum (system )?requirements|tech(nical)? specs)\b/i';
	const WINDOW  = 70;
	const STOP    = '/^(reviews?|faqs?|frequently asked|powered by|\d(\.\d)? star|related products|you may also like|what is |customers also)/i';

	/** macOS name → version, oldest first. */
	const MAC = [ 'mavericks' => '10.9', 'yosemite' => '10.10', 'el capitan' => '10.11', 'sierra' => '10.12', 'high sierra' => '10.13', 'mojave' => '10.14', 'catalina' => '10.15', 'big sur' => '11', 'monterey' => '12', 'ventura' => '13', 'sonoma' => '14', 'sequoia' => '15', 'tahoe' => '26' ];

	const FORMATS = [
		'aax'        => '/\bAAX\b/',
		'au'         => '/\bAU\b|\bAudio ?Units?\b/i',
		'vst-3'      => '/\bVST ?3\b/i',
		'vst-2'      => '/\bVST[ \x{00A0}]?2\b|\bVST\b(?![ \x{00A0}]?-?3)/iu', // "VST 3" may carry a non-breaking space (IK)
		'clap'       => '/\bCLAP\b/',
		'standalone' => '/\bstand-?alone\b/i',
	];
	const NOT_SUPPORTED = '/no longer|not (be )?supported|discontinued|deprecated|dropped|removed|unsupported/i';
	/** A line naming one OS (parse() gives such a line to that OS). */
	const OS_RE = [
		'mac'     => '/\bmac ?os\b|\bos ?x\b|\bmac\b|apple silicon|\bM[1-4]\b/i',
		'windows' => '/\bwindows\b|\bwin ?(7|8|10|11)\b|\(pc\)|\bpc\b/i',
		'linux'   => '/\blinux\b|ubuntu/i',
	];
	/**
	 * Starts the HTML of a part the person picked as the requirements (VendorMaps::picked_requirements()): it is read
	 * whole, without the section heading and three requirement signs a page needs, and an OS it names counts as a
	 * platform even with nothing more said (soundiron's badges, 2026-11-02: "Mac OSX 10.9 or later", "Windows").
	 */
	const PICKED = '<!--mmpt-picked-requirements-->';

	/**
	 * @return array{mac:array,windows:array,linux:array,platforms:string[],licensing:array,evidence:string}|null
	 */
	public static function extract( string $html ): ?array {
		$lines = self::lines( $html );
		if ( str_starts_with( $html, self::PICKED ) ) {
			return self::picked( $lines );
		}
		$best  = null;
		$score = 0;
		foreach ( $lines as $i => $line ) {
			if ( mb_strlen( $line ) > 80 || ! preg_match( self::SECTION, $line ) ) {
				continue;
			}
			$window = self::window( array_slice( $lines, $i, self::WINDOW ) );
			if ( self::score( $window ) < 3 ) {
				continue;
			}
			// A short summary ("macOS & Windows 10/11") often precedes the full section: the window
			// that yields the most fields wins, and a later one wins a tie.
			$parsed = self::parse( $window );
			$s      = 0;
			foreach ( [ 'mac', 'windows', 'linux' ] as $os ) {
				$s += count( $parsed[ $os ] );
			}
			if ( $s > 0 && $s >= $score ) {
				$best  = $parsed;
				$score = $s;
			}
		}
		return $best;
	}

	/** A sampler or player a library runs in (owner 2026-11-03: "Full Kontakt 5.5 or later" was read and dropped). */
	const PLAYER = '(?:kontakt(?: player)?|kontakt \\d+ player|uvi (?:workstation|falcon)|sine player|opus|play \\d|halion(?: sonic)?|sforzando|decent ?sampler|engine ?2|spitfire (?:audio )?app|omnisphere|mach ?five)';

	/**
	 * A line saying which player a product needs: a player with its version ("KONTAKT Version 7.10.7 or higher",
	 * "Kontakt 6.6+"), the free or full version of one, or one required ("requires KONTAKT PLAYER 6.2.1", "No
	 * additional sampler or Kontakt installation is required"). Not help text ("If you require legacy installers…",
	 * "Please also see the KONTAKT 6 PLAYER FAQ"), registration notes, or an OS line naming a player in passing.
	 */
	public static function player_line( string $l ): bool {
		$p = self::PLAYER;
		return mb_strlen( $l ) <= 220
			&& preg_match( '/\\b' . $p . '\\b/i', $l )
			&& ! preg_match( '/^(?:if you|please|see |for more)|installer|registration|register|refer to|knowledge ?base|\\bfaq\\b|\\bmac\\b|mac ?os|os ?x|windows|\\bwin\\b/i', $l )
			&& (bool) preg_match(
				'/\\b' . $p . '\\b[^.\\n]{0,25}?\\d+(?:\\.\\d+)*\\s*(?:\\+|or (?:later|higher|newer|above)|and (?:later|higher|above))'
				. '|\\b(?:free|full(?: retail)?(?: version)?)\\b[^.\\n]{0,40}\\b' . $p . '\\b'
				. '|\\brequire[sd]?\\b[^.\\n]{0,40}\\b' . $p . '\\b|\\b' . $p . '\\b[^.\\n]{0,40}\\b(?:is |are )?(?:required|needed)\\b/i',
				$l
			);
	}

	/** A picked part's requirements (PICKED), or null when it names no OS. */
	protected static function picked( array $lines ): ?array {
		$out  = self::parse( $lines );
		$text = implode( "\n", $lines );
		foreach ( self::OS_RE as $os => $re ) {
			if ( ! in_array( $os, $out['platforms'], true ) && preg_match( $re, $text ) ) {
				$out['platforms'][] = $os;
			}
		}
		$out['platforms'] = array_values( array_intersect( array_keys( self::OS_RE ), $out['platforms'] ) );
		return $out['platforms'] ? $out : null;
	}

	/** The section's lines, up to where the page moves on to reviews or FAQs. */
	protected static function window( array $lines ): array {
		$out = [];
		foreach ( $lines as $k => $line ) {
			if ( $k > 3 && ( preg_match( self::STOP, $line ) || ( $k > 2 && preg_match( self::SECTION, $line ) && mb_strlen( $line ) < 40 && count( $out ) > 6 ) ) ) {
				break;
			}
			$out[] = $line;
		}
		return $out;
	}

	protected static function score( array $lines ): int {
		$text = implode( "\n", $lines );
		$n    = 0;
		foreach ( [ '/\bmac ?os\b|\bmac\b/i', '/\bwindows\b|\bwin\b/i', '/\bGB\b/', '/\b(AAX|VST ?3|AU)\b/', '/\b(intel|apple silicon|processor|cpu)\b/i', '/\b64[- ]?bit\b/i' ] as $re ) {
			$n += preg_match( $re, $text ) ? 1 : 0;
		}
		return $n;
	}

	/** Split the section into per-OS and shared blocks, then read each. */
	public static function parse( array $lines ): array {
		$blocks = [ 'mac' => [], 'windows' => [], 'linux' => [], 'all' => [], 'formats' => [], 'hosts' => [] ];
		$cur    = 'all';
		foreach ( $lines as $line ) {
			// A legal footer ("VST is a trademark of its owner…", "Windows Vista and the Windows logo…") is not a requirement.
			if ( mb_strlen( $line ) > 150 && preg_match( '/\btrademarks?\b/i', $line ) ) {
				continue;
			}
			foreach ( self::segments( $line ) as [ $label, $rest ] ) {
				if ( $label !== '' ) {
					$cur = $label;
				}
				if ( $rest !== '' ) {
					$blocks[ $cur ][] = $rest;
				}
			}
		}
		// Unlabelled lines naming exactly one OS belong to that OS ("Mac OS X 10.15 or higher", "Windows 10 &
		// Above" next to "MacOS | 64bit …" — Undertone, 2026-10-08); the rest stays shared.
		$keep = [];
		foreach ( $blocks['all'] as $l ) {
			$hit = array_keys( array_filter( self::OS_RE, static fn( $re ) => (bool) preg_match( $re, $l ) ) );
			if ( count( $hit ) === 1 ) {
				$blocks[ $hit[0] ][] = $l;
			} else {
				$keep[] = $l;
			}
		}
		$blocks['all'] = $keep;
		$shared  = implode( "\n", array_merge( $blocks['all'], $blocks['formats'] ) );
		$formats = self::formats( implode( "\n", $blocks['formats'] ) );
		$hosts   = self::host_list( $blocks['hosts'] );
		$out     = [ 'platforms' => [], 'licensing' => self::licensing( implode( "\n", $lines ) ), 'evidence' => implode( "\n", $lines ) ];
		foreach ( [ 'mac', 'windows', 'linux' ] as $os ) {
			$text = implode( "\n", $blocks[ $os ] );
			$req  = $text === '' ? [] : self::read_os( $os, $text, $shared, $formats, $hosts );
			if ( $req ) {
				$out['platforms'][] = $os;
			}
			$out[ $os ] = $req;
		}
		return $out;
	}

	/**
	 * A line → [label, text] pieces. A label switches the block ("Mac:",
	 * "Win:", "PC Version (Windows)", "All Computer Systems:", "Plugin Formats").
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	protected static function segments( string $line ): array {
		// "Mac® (64-bits)", "Windows® (64-bits)" (IK Multimedia): trademark signs and a bitness note never stop a label.
		$line   = trim( (string) preg_replace( '/[®™]|\s*\((?:32|64)[- ]?bits?\)/iu', '', $line ) );
		$labels = [
			'mac'     => '(?:mac(?:os)?|mac version(?: \(mac ?os\))?|apple mac(?:os)?)',
			'windows' => '(?:win(?:dows)?|pc(?: version)?(?: \(windows\))?|windows version)',
			'linux'   => '(?:linux(?: version)?)',
			'all'     => '(?:all (?:computer )?systems|all platforms|general)',
			'formats' => '(?:(?:supported |plug-?in )formats?|plug-?ins? formats?)',
			'hosts'   => '(?:supported (?:hosts|daws)|compatible (?:hosts|daws)|tested hosts)',
		];
		foreach ( $labels as $key => $re ) {
			// The whole line is a label ("Mac Version (macOS)", "Supported Hosts").
			if ( preg_match( '/^' . $re . '\s*[:|]?$/i', $line ) ) {
				return [ [ $key, '' ] ];
			}
		}
		// Inline labels: "Mac: macOS 14 … Win: Windows 10 …".
		// "MacOS | 64bit / M1 Native: VST3, AU, AAX" — a pipe after the OS name is a label too.
		$alt   = implode( '|', array_map( static fn( $k, $re ) => '(?<' . $k . '>' . $re . ')\s*[:|]', array_keys( $labels ), $labels ) );
		$parts = preg_split( '/(?=\b(?:' . implode( '|', $labels ) . ')\s*[:|])/i', $line, -1, PREG_SPLIT_NO_EMPTY );
		$out   = [];
		foreach ( $parts as $p ) {
			if ( preg_match( '/^(?:' . $alt . ')\s*(.*)$/is', $p, $m ) ) {
				foreach ( array_keys( $labels ) as $k ) {
					if ( ( $m[ $k ] ?? '' ) !== '' ) {
						$out[] = [ $k, trim( (string) end( $m ) ) ];
						continue 2;
					}
				}
			}
			$out[] = [ '', trim( $p ) ];
		}
		return $out;
	}

	protected static function read_os( string $os, string $text, string $shared, array $shared_formats, string $hosts ): array {
		$all = $text . "\n" . $shared;
		// The OS line itself, not later notes ("a known issue exists on macOS Mojave").
		$req = [ 'version' => '' ];
		foreach ( explode( "\n", $text ) as $l ) {
			$v = $os === 'mac' ? self::mac_version( $l ) : ( $os === 'windows' ? self::windows_version( $l ) : '' );
			if ( $v !== '' ) {
				$req['version'] = $v;
				break;
			}
		}
		if ( $os === 'linux' ) {
			$req['notes'] = self::clip( (string) preg_replace( '/\s*Please see .*$/i', '', strtok( $text, "\n" ) ), 200 );
			if ( preg_match( '/supported distributions?:?\s*(.+)/i', $text, $m ) ) {
				$req['version'] = self::clip( $m[1], 200 );
			}
		}
		foreach ( explode( "\n", $text ) as $l ) {
			$cpu_re = '/apple silicon|\bM\d\b|\bintel\b|\bamd\b|ryzen|core i\d|processor|\bcpu\b|arm64|x86/i';
			if ( preg_match( $cpu_re, $l ) ) {
				// Only the processor part: "10th gen Intel or AMD equivalent (PC) 8GB of RAM" → before the RAM;
				// "64bit / M1 Native: VST3, AU, AAX" → "M1 Native" (Undertone, 2026-10-08).
				$c = (string) preg_split( '/\s*\d+(?:\.\d+)?\s*GB\b/i', $l )[0];
				$c = (string) preg_replace( '/\s*:\s*(?:(?:VST ?\d?|AUv?3?|AAX|CLAP|RTAS|standalone)[\s,\/&]*)+$/i', '', $c );
				$c = (string) preg_replace( '/^\s*(?:32|64)[- ]?bit\s*[\/|,]\s*/i', '', $c );
				$c = trim( (string) preg_replace( '/\s*\((?:pc|mac|windows)\)/i', '', $c ), "*•-–,; \t" );
				if ( $c !== '' && preg_match( $cpu_re, $c ) ) {
					$req['cpu'] = self::clip( ( isset( $req['cpu'] ) ? $req['cpu'] . '; ' : '' ) . $c, 200 );
				}
			}
		}
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*GB\s*(?:of\s+)?(?:RAM|memory)|(?:RAM|memory)\D{0,12}(\d+(?:\.\d+)?)\s*GB/i', $all, $m ) ) {
			$req['ram'] = (float) ( $m[1] !== '' ? $m[1] : $m[2] );
		}
		if ( preg_match( '/(\d+(?:\.\d+)?)\s*GB\s*(?:of\s+)?(?:free\s+)?(?:hd\s+|hard[- ]?(?:disk|drive)\s+|disk\s+|drive\s+)?(?:space|storage)|(\d+(?:\.\d+)?)\s*GB\s*(?:of\s+)?free/i', $all, $m ) ) {
			$req['disk'] = (float) ( $m[1] !== '' ? $m[1] : $m[2] );
		}
		$formats = array_values( array_unique( array_merge( $shared_formats, self::formats( $text ) ) ) );
		if ( $os !== 'mac' ) {
			$formats = array_values( array_diff( $formats, [ 'au' ] ) ); // Audio Units exist only on macOS
		}
		if ( $formats ) {
			$req['plugins'] = $formats;
		}
		$bits = [];
		if ( preg_match( '/\b64[- ]?bit\b/i', $all ) ) {
			$bits[] = '64_bit';
		}
		if ( preg_match( '/\b32[- ]?bit\b/i', $all ) && ! preg_match( '/(no|not|without)[^.\n]{0,30}32[- ]?bit|32[- ]?bit[^.\n]{0,30}(not|no longer)/i', $all ) ) {
			$bits[] = '32_bit';
		}
		if ( $bits ) {
			$req['support'] = $bits;
		}
		if ( preg_match( '/internet (connection|access)|online activation|requires? (an )?internet/i', $all ) ) {
			$req['internet_required'] = true;
		}
		// Other requirements, one per line (the product editor's field): the player it needs, then the hosts.
		$also = array_values( array_unique( array_filter( array_map( 'trim', explode( "\n", $all ) ), [ self::class, 'player_line' ] ) ) );
		if ( $hosts !== '' && $os !== 'linux' ) {
			$also[] = 'Supported hosts: ' . self::clip( $hosts, 400 );
		}
		if ( $also ) {
			$req['additional_requirements'] = implode( "\n", array_slice( $also, 0, 4 ) );
		}
		return array_filter( $req, static fn( $v ) => $v !== '' && $v !== [] && $v !== 0.0 );
	}

	/** The comma-separated list in the hosts block, without its lead-in ("…tested with the following applications;"). */
	protected static function host_list( array $lines ): string {
		$best = '';
		foreach ( preg_split( '/\n|(?<=[;:])\s*/', implode( "\n", $lines ) ) as $seg ) {
			if ( substr_count( $seg, ',' ) > substr_count( $best, ',' ) ) {
				$best = $seg;
			}
		}
		return trim( $best, " .;" );
	}

	/** Formats a text names, minus the ones it says are no longer supported. @return string[] */
	protected static function formats( string $text ): array {
		$out = [];
		foreach ( preg_split( '/(?<=[.;])\s+|\n/', $text ) as $sentence ) {
			if ( preg_match( self::NOT_SUPPORTED, $sentence ) ) {
				continue;
			}
			foreach ( self::FORMATS as $key => $re ) {
				if ( preg_match( $re, $sentence ) ) {
					$out[] = $key;
				}
			}
		}
		return array_values( array_unique( $out ) );
	}

	/** "macOS 12 Monterey or later" when open-ended, else the tested list "macOS 14 Sonoma, 15 Sequoia, 26 Tahoe". */
	public static function mac_version( string $text ): string {
		$found = [];
		foreach ( self::MAC as $name => $ver ) {
			if ( preg_match( '/\b' . str_replace( ' ', '\s+', $name ) . '\b/i', $text ) && ! ( $name === 'sierra' && preg_match( '/high\s+sierra/i', $text ) && ! preg_match( '/(?<!high\s)\bsierra\b/i', $text ) ) ) {
				$found[ $ver ] = true;
			}
		}
		if ( preg_match_all( '/\b(?:mac ?os(?: x)?|os ?x)\s*(1[0-9](?:\.\d+)?|2[0-9])/i', $text, $m ) ) {
			foreach ( $m[1] as $v ) {
				$found[ self::mac_major( $v ) ] = true;
			}
		}
		if ( ! $found ) {
			return '';
		}
		$vers = array_keys( $found );
		usort( $vers, 'version_compare' );
		$names = array_flip( self::MAC );
		$label = static fn( $v ) => trim( $v . ' ' . ucwords( (string) ( $names[ $v ] ?? '' ) ) );
		$open = (string) preg_replace( '/\([^)]*\)/', '', $text ); // "(version 10.0.14393 or higher)" is a build, not an OS range
		if ( count( $vers ) === 1 || preg_match( '/or (later|higher|newer)|and (up|later|above|newer)|\+/i', $open ) ) {
			return 'macOS ' . $label( $vers[0] ) . ' or later';
		}
		return 'macOS ' . implode( ', ', array_map( $label, $vers ) );
	}

	/** "10.15.7" → "10.15", "14.7" → "14". */
	protected static function mac_major( string $v ): string {
		$parts = explode( '.', $v );
		return (int) $parts[0] === 10 ? '10.' . ( $parts[1] ?? '0' ) : (string) (int) $parts[0];
	}

	public static function windows_version( string $text ): string {
		$found = [];
		if ( preg_match_all( '/\b(?:windows|win)\s*((?:\d+(?:\.\d)?|xp|vista)(?:\s*(?:\/|,|or|and|&)\s*(?:\d+(?:\.\d)?))*)/i', $text, $m ) ) {
			foreach ( $m[1] as $group ) {
				foreach ( preg_split( '/\s*(?:\/|,|or|and|&)\s*/i', $group ) as $v ) {
					$v = strtolower( trim( $v ) );
					if ( in_array( $v, [ 'xp', 'vista', '7', '8', '8.1', '10', '11' ], true ) ) {
						$found[ $v ] = true;
					}
				}
			}
		}
		if ( ! $found ) {
			return '';
		}
		$order = [ 'xp', 'vista', '7', '8', '8.1', '10', '11' ];
		$text  = (string) preg_replace( '/\([^)]*\)/', '', $text );
		$vers  = array_values( array_intersect( $order, array_keys( $found ) ) );
		$names = array_map( static fn( $v ) => in_array( $v, [ 'xp', 'vista' ], true ) ? strtoupper( $v ) : $v, $vers );
		if ( count( $vers ) === 1 && ! preg_match( '/or (later|higher|newer)|and (up|later|above)/i', $text ) ) {
			return 'Windows ' . $names[0];
		}
		if ( preg_match( '/or (later|higher|newer)|and (up|later|above)/i', $text ) ) {
			return 'Windows ' . $names[0] . ' or later';
		}
		return 'Windows ' . implode( ' or ', $names );
	}

	/** iLok cloud / USB, or plain computer activation, when the section names them. */
	protected static function licensing( string $text ): array {
		if ( preg_match( '/\biLok\b/i', $text ) ) {
			$types = [];
			if ( preg_match( '/iLok cloud/i', $text ) ) {
				$types[] = 'cloud';
			}
			if ( preg_match( '/iLok ?\d|usb[^\n]{0,20}iLok|iLok[^\n]{0,40}usb|dongle/i', $text ) ) {
				$types[] = 'usb';
			}
			if ( preg_match( '/machine|computer[- ]based|local (computer|license)/i', $text ) ) {
				$types[] = 'machine';
			}
			return [ 'methods' => [ 'ilok' ], 'ilok' => $types ];
		}
		return [];
	}

	/**
	 * The oldest OS a stored requirements version text names: ['mac' => '10.13'],
	 * ['windows' => '7']. '' when it names none.
	 */
	public static function min_os( string $os, string $version ): string {
		if ( $os === 'mac' ) {
			$v = self::mac_version( $version );
			return preg_match( '/^macOS (\d+(?:\.\d+)?)/', $v, $m ) ? $m[1] : '';
		}
		if ( $os === 'windows' ) {
			$v = self::windows_version( $version );
			return preg_match( '/^Windows (\S+)/', $v, $m ) ? strtolower( $m[1] ) : '';
		}
		return '';
	}

	const OS_CONCEPT = [ 'mac' => 'requirements_mac', 'windows' => 'requirements_windows', 'linux' => 'requirements_linux' ];

	/**
	 * What fill_product() would decide for each field of $req, writing nothing: field (mac, windows, linux,
	 * platforms, licensing) => fill | same | locked | replace (clearly outdated, owner 2026-10-07) | review.
	 * Platforms are fill (some to add), same or locked, never review: they're only added to.
	 * fill_product() writes only the fills; the Vendor maps product preview shows all of them.
	 * @return array<string,string>
	 */
	public static function plan( int $pid, array $req ): array {
		$out = [];
		foreach ( self::OS_CONCEPT as $os => $concept ) {
			if ( ! empty( $req[ $os ] ) ) {
				$out[ $os ] = self::decide( $pid, $concept, $req[ $os ], $os );
			}
		}
		if ( ! empty( $req['platforms'] ) ) {
			$out['platforms'] = self::platforms_action( $pid, (array) $req['platforms'] );
		}
		if ( ! empty( $req['licensing'] ) ) {
			$out['licensing'] = self::decide( $pid, 'licensing', $req['licensing'], '' );
		}
		return $out;
	}

	/** fill (adds a platform), same (all listed already), locked or unavailable. */
	public static function platforms_action( int $pid, array $platforms ): string {
		if ( ! class_exists( 'MMI_Product_Content_Registry' ) ) {
			return 'unavailable';
		}
		if ( class_exists( 'MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( $pid, \MMI_Product_Content_Registry::canonical_key( 'platforms' ) ) ) {
			return 'locked';
		}
		$current = (array) \MMI_Product_Content_Registry::canonical_value( $pid, 'platforms' );
		return array_diff( array_intersect( array_keys( \MMI_Product_Content_Registry::PLATFORM_LABELS ), array_map( 'strtolower', $platforms ) ), $current ) ? 'fill' : 'same';
	}

	public static function fill_product( int $pid, array $req ): array {
		$out    = [];
		$backup = [];
		$write  = [];
		foreach ( self::plan( $pid, $req ) as $field => $action ) {
			$out[ $field ] = $action === 'fill' ? 'fill' : ( in_array( $action, [ 'same', 'locked' ], true ) ? $action : 'kept' );
			if ( $action === 'fill' && $field !== 'platforms' ) {
				$write[ self::OS_CONCEPT[ $field ] ?? $field ] = $req[ $field ];
			}
		}
		// A platform under review (stored requirements differ from the page) still gets the player the page names, added
		// to its note when what is stored names none (owner 2026-11-03). Only added; not locked, so the review stays open.
		$notes = [];
		foreach ( $out as $field => $action ) {
			$concept = self::OS_CONCEPT[ $field ] ?? '';
			if ( $action !== 'kept' || $concept === '' || ! empty( $write[ $concept ] ) ) {
				continue;
			}
			$current = (array) \MMI_Product_Content_Registry::canonical_value( $pid, $concept );
			$add     = self::players_to_add( $current, (array) ( $req[ $field ] ?? [] ) );
			if ( $current && $add ) {
				$backup[ $concept ]                 = $current;
				$current['additional_requirements'] = trim( (string) ( $current['additional_requirements'] ?? '' ) . "\n" . implode( "\n", $add ) );
				\MMI_Product_Content_Registry::store( $pid, $concept, $current );
				$out[ $field ] = 'note';
				$notes[]       = $field;
			}
		}
		$locked = [];
		foreach ( $write as $concept => $value ) {
			$backup[ $concept ] = \MMI_Product_Content_Registry::canonical_value( $pid, $concept );
			\MMI_Product_Content_Registry::store( $pid, $concept, $value );
			if ( class_exists( 'MMI_Pipeline_Field_Locks' ) ) {
				$key = \MMI_Product_Content_Registry::canonical_key( $concept );
				\MMI_Pipeline_Field_Locks::lock( $pid, $key );
				$locked[] = $key;
			}
		}
		// Platforms only grow, so they aren't locked: a later feed or page may add one more.
		if ( ( $out['platforms'] ?? '' ) === 'fill' ) {
			$backup['platforms'] = \MMI_Product_Content_Registry::canonical_value( $pid, 'platforms' );
			\MMI_Product_Content_Registry::merge_platforms( $pid, (array) $req['platforms'] );
		}
		if ( $write && class_exists( 'MMI_Product_Content_Registry' ) ) {
			\MMI_Product_Content_Registry::sync_platforms( $pid );
		}
		if ( $backup ) {
			// What the fill replaced, kept in the plugin's private folder (outside the web root where the host allows).
			$dir = mmi_shared_lib_private_subdir( 'mmpt-requirements-backups' );
			if ( $dir !== '' ) {
				file_put_contents( $dir . gmdate( 'Ymd-His' ) . '-product-' . $pid . '.json', wp_json_encode( [ $pid => $backup ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'product_requirements.fill', [ 'object_type' => 'product', 'object_id' => (string) $pid, 'details' => $out + ( $locked ? [ 'locked' => $locked ] : [] ) ] );
			}
		}
		return $out;
	}

	/** The page's player lines a stored platform lacks: none when what is stored already names a player. @return string[] */
	public static function players_to_add( array $current, array $page ): array {
		$stored = implode( ' ', array_map( 'strval', array_intersect_key( $current, array_flip( [ 'version', 'notes', 'additional_requirements' ] ) ) ) );
		if ( preg_match( '/\\b' . self::PLAYER . '\\b/i', $stored ) ) {
			return [];
		}
		return array_values( array_filter( explode( "\n", (string) ( $page['additional_requirements'] ?? '' ) ), [ self::class, 'player_line' ] ) );
	}

	protected static function decide( int $pid, string $concept, $value, string $os ): string {
		// The requirement fields live in MMI Data Pipeline's product-spec store. Without it there is
		// nothing to read or write; every caller writes only on fill/replace, so this also keeps
		// apply() and fill_product() from calling the missing class.
		if ( ! class_exists( 'MMI_Product_Content_Registry' ) ) {
			return 'unavailable';
		}
		if ( class_exists( 'MMI_Pipeline_Field_Locks' ) && \MMI_Pipeline_Field_Locks::is_locked( $pid, \MMI_Product_Content_Registry::canonical_key( $concept ) ) ) {
			return 'locked';
		}
		$current = \MMI_Product_Content_Registry::canonical_value( $pid, $concept );
		if ( ! \MMI_Product_Content_Registry::differs_from_stored( $pid, $concept, $value ) ) {
			return 'same';
		}
		if ( ! $current ) {
			return 'fill';
		}
		return $os !== '' && self::outdated( (array) $current, $os ) ? 'replace' : 'review';
	}

	/** Owner's "clearly outdated" (2026-10-07): 32-bit only, or the oldest OS named is before macOS 11 / Windows 10. */
	public static function outdated( array $req, string $os ): bool {
		$bits = (array) ( $req['support'] ?? [] );
		if ( $bits === [ '32_bit' ] ) {
			return true;
		}
		$min = self::min_os( $os, (string) ( $req['version'] ?? '' ) . ' ' . (string) ( $req['notes'] ?? '' ) );
		if ( $os === 'mac' ) {
			return $min !== '' && version_compare( $min, '11', '<' );
		}
		if ( $os === 'windows' ) {
			return in_array( $min, [ 'xp', 'vista', '7', '8', '8.1' ], true );
		}
		return false;
	}

	protected static function clip( string $text, int $max ): string {
		$text = trim( $text );
		return mb_strlen( $text ) <= $max ? $text : rtrim( (string) preg_replace( '/\s+\S*$/u', '', mb_substr( $text, 0, $max ) ), ' ,;' ) . '…';
	}

	/* ── Page text ───────────────────────────────────────────────────────── */

	const BLOCKS = [ 'ADDRESS', 'ARTICLE', 'ASIDE', 'BLOCKQUOTE', 'BR', 'DD', 'DETAILS', 'DIV', 'DL', 'DT', 'FIGCAPTION', 'FIGURE', 'FOOTER', 'FORM', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'HEADER', 'HR', 'LI', 'MAIN', 'NAV', 'OL', 'P', 'PRE', 'SECTION', 'SUMMARY', 'TABLE', 'TD', 'TH', 'TR', 'UL' ];

	/**
	 * The page's visible text as lines: a new line wherever a block element
	 * starts or ends, so "Mac Version (macOS)" and "macOS 12 or later" stay
	 * apart even when the page sets them in adjacent elements.
	 *
	 * @return string[]
	 */
	public static function lines( string $html ): array {
		if ( $html === '' || ! class_exists( '\Dom\HTMLDocument' ) ) {
			return [];
		}
		$doc = \Dom\HTMLDocument::createFromString( $html, LIBXML_NOERROR );
		foreach ( $doc->querySelectorAll( 'script, style, noscript, template, svg' ) as $junk ) {
			$junk->remove();
		}
		$buf   = '';
		$walk  = static function ( \Dom\Node $n ) use ( &$walk, &$buf ) {
			foreach ( $n->childNodes as $c ) {
				if ( $c->nodeType === XML_TEXT_NODE ) {
					$buf .= $c->textContent;
				} elseif ( $c instanceof \Dom\Element ) {
					$block = in_array( $c->tagName, self::BLOCKS, true );
					$buf  .= $block ? "\n" : '';
					$walk( $c );
					$buf  .= $block ? "\n" : '';
				}
			}
		};
		if ( $doc->body ) {
			$walk( $doc->body );
		}
		$out = [];
		foreach ( explode( "\n", html_entity_decode( $buf, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $line ) {
			$line = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $line ) );
			if ( $line !== '' ) {
				$out[] = $line;
			}
		}
		return $out;
	}
}
