<?php
/**
 * Brief product descriptions for titles, taken verbatim from vendor copy.
 *
 * User rule (2026-09-25): every title carries a very brief description
 * taken directly from the product's own webpage copy — "Maker
 * MTurboReverb Revolutionary Algorithmic Reverb (Download)", from Melda's
 * "MTurboReverb is a revolutionary algorithmic reverb, which features…".
 *
 * So a description is never written, only *selected*: a 2–6 word span that
 * must appear word-for-word in a stored copy block (verify()). Every row
 * keeps that copy block and where it came from, so a reviewer can see the
 * phrase in context. Claude selects (Picker); the "<Product> is a <phrase>,"
 * pattern (pick_by_pattern()) is recorded alongside for comparison. Every
 * pick goes through verify() and acceptable().
 *
 * Copy sources, most trusted first: the vendor's own product page
 * (VendorFetcher), XChange web-assets long_description (vendor-supplied),
 * the store's post_content, XChange's one-line `descript`. (Not the old
 * scraper's description_scrub — it's AI-rewritten, see SOURCES — nor
 * post_content that reads as AI-written marketing copy, see AI_COPY.)
 *
 * One description per product family (family_shared()): SKUs of the same
 * Product Paths family (Media Composer Ultimate 1-Year, 2-Year, Renewal…)
 * share the best-sourced phrase instead of each carrying its own pick.
 *
 * Statuses: pending (no copy yet) · candidate (picked + verified, awaiting
 * review) · approved · rejected · none (copy exists, nothing usable in it).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Descriptions {

	/** Words that make a product hardware, not a download (was Catalog::HARDWARE_RX). */
	const HARDWARE_RX = '/\b(audio interface|\d+-channel\b.*\binterface|hardware|power supply|rack ?mount|studio monitors?|monitor speakers?|midi (keyboard|controller)|keyboard controller|(xlr|trs|patch|instrument|microphone|mic) cables?|condenser microphones?|dynamic microphones?|pedal(?!s\b)|(keyboard|monitor|mic|laptop) stand)\b/i';


	const DB_VERSION = '1';
	const DB_OPTION  = 'mmpt_desc_db_version';
	const MIN_WORDS  = 2;
	const MAX_WORDS  = 6;
	/** A phrase a person takes from vendor copy may run longer (Sibelius: "full toolset to compose, arrange, and engrave"). */
	const MANUAL_MAX_WORDS = 8;
	const COPY_CHARS = 2000;

	/** Source priority (lower = more trusted). */
	/**
	 * Source priority (lower = more trusted). `description_scrub` is deliberately
	 * absent: checked 2026-09-26, it is AI-rewritten marketing copy ("Unlock a new
	 * realm…", markdown bold), not the vendor's words.
	 */
	const SOURCES = [ 'vendor_page' => 1, 'web_assets' => 2, 'post_content' => 3, 'xchange_descript' => 4 ];

	/** How the "Why" names each source. post_content is our own store copy, not the vendor's. */
	const SOURCE_LABELS = [ 'vendor_page' => 'the vendor page', 'web_assets' => 'XChange vendor copy', 'post_content' => 'our store copy (not the vendor\'s)', 'xchange_descript' => 'the XChange description' ];

	/**
	 * Store copy that opens like generated marketing text ("Elevate your
	 * post-production workflow…", "Unlock the…") is not the vendor's words and
	 * is not used (user decision 2026-09-30). Measured that day: 897 of 1,868
	 * store-copy picks came from such text, among them "digital audio
	 * workstation" for a video editor.
	 */
	const AI_COPY = '/^\W*(elevate|unlock|transform|experience|discover|enhance|unleash|empower|immerse yourself|dive into|upgrade your|expand your|take your|step into|revolutioni[sz]e|supercharge|harness|master the|streamline|introducing)\b/iu';

	/**
	 * A family SKU whose title names an add-on (PhraseFind Option, Huddle Add-On)
	 * is its own product even when Product Paths files it in the main family, so it
	 * neither gives nor takes the family description — unless the family itself is one.
	 */
	const ADDON_RX = '/\b(options?|add-?ons?|expansions?|packs?|plug-?ins? only)\b/i';

	/** A phrase must end in what the product *is* ("… reverb", "… sample library"). */
	const HEAD_NOUNS = 'plug-?ins?|reverbs?|delays?|echo|compressors?|limiters?|eq|equali[sz]ers?|synth(esizer)?s?|soft-?synth|instruments?|librar(y|ies)|samplers?|emulations?|processors?|effects?|fx|tools?|toolkit|toolbox|suites?|bundles?|collections?|packs?|workstation|daw|editors?|drum machines?|pianos?|guitars?|bass|strings|choirs?|orchestra|generators?|channel[- ]strips?|preamps?|saturators?|maximi[sz]ers?|analy[sz]ers?|meters?|modulators?|filters?|loopers?|sequencers?|vocoders?|amps?|amplifiers?|cabinets?|expansions?|soundbanks?|software|app|application|engine|recorder|player|chorus|flanger|phaser|tremolo|distortion|overdrive|fuzz|gate|de-?essers?|transient shaper|console|mixer|imager|widener|exciter|enhancer|tuner|harmoni[sz]er|arpeggiator|drums?|percussion|kits?|loops|presets|patches|sounds|voices?|rompler|module|machine|pedal|simulator|modeler|designer|shaper|resonator|spatiali[sz]er|panner|converter|denoiser|course|method|plugin|stereo tool|mastering tool|monitoring|calibration|correction|restoration|repair|chain|impulse responses|responses|cabs|cabinet irs|recorder|looper|sampler|kit expansion|remote control software|platform|controller|system|textures|samples|patterns|workstation|environment|studio|toolkit|\\w+ing';

	/** Where a phrase stops ("reverb, which features…", "delay plugin with 20 modes"). */
	const STOP = '/,|;|:|\.(\s|$)|\s[-–—]\s|\(|(?<=\s)(that|which|with|for|to|designed|featuring|offering|built|made|recorded|created|from|inspired|based|in|by|where|who|capable|providing|giving|than|of the|and more)(?=\s|$)/iu';

	/**
	 * Products whose title says what kind of thing they are: the phrase must
	 * say the same kind, or it's describing something else (a course's copy
	 * describing the console it teaches; an expansion's copy describing its
	 * host synth).
	 */
	const KINDS = [
		'course'    => '/\b(course|training|tutorials?|lessons?|video|method)\b/i',
		'expansion' => '/\b(expansion|pack|library|libraries|presets?|patch(es)?|sounds?|soundbank|collection|kits?|loops|grooves|irs?|rigs?)\b/i',
	];

	public static function product_kind( string $title ): string {
		if ( preg_match( '/\b(course|training|tutorials?|lessons?|video series)\b/i', $title ) ) {
			return 'course';
		}
		if ( preg_match( '/\b(expansion|sound ?pack|preset pack|patch (library|bank)|rig library|ir library|soundbank|presets for|patches for|collection for|pack for|library for)\b/i', $title ) ) {
			return 'expansion';
		}
		return '';
	}

	const DAILY_HOOK = 'mmpt_daily_describe';

	public static function register_hooks(): void {
		add_action( self::DAILY_HOOK, [ __CLASS__, 'refresh_all' ] );
	}

	public static function schedule_daily(): void {
		if ( ! wp_next_scheduled( self::DAILY_HOOK ) ) {
			// 04:40 site time — before the 04:50 title composition reads these rows.
			$ts = strtotime( 'tomorrow 04:40', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
			wp_schedule_event( $ts, 'daily', self::DAILY_HOOK );
		}
	}

	/** Refresh every digital product's row (~11 s for 6.7k products). */
	public static function refresh_all(): array {
		$tot = [];
		foreach ( array_chunk( Exclusions::eligible_ids(), 500 ) as $chunk ) {
			$digital = array_keys( array_filter( Exclusions::digital( $chunk ) ) );
			foreach ( self::refresh( $digital ) as $k => $n ) {
				$tot[ $k ] = ( $tot[ $k ] ?? 0 ) + $n;
			}
		}
		Module::info( 'Descriptions refreshed', $tot );
		return $tot;
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_descriptions';
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
  status varchar(20) NOT NULL DEFAULT 'pending',
  phrase varchar(120) NOT NULL DEFAULT '',
  method varchar(20) NOT NULL DEFAULT '',
  source_type varchar(30) NOT NULL DEFAULT '',
  source_url varchar(500) NOT NULL DEFAULT '',
  excerpt text,
  vendor_url varchar(500) NOT NULL DEFAULT '',
  vendor_copy text,
  fetch_status varchar(40) NOT NULL DEFAULT '',
  fetched_at datetime DEFAULT NULL,
  picked_at datetime DEFAULT NULL,
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  note varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY  (product_id),
  KEY status (status),
  KEY fetch_status (fetch_status)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/** @return array<int,array> product_id => row, for the given ids (one query). */
	public static function get_many( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE product_id IN (' . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) {
			$out[ (int) $r['product_id'] ] = $r;
		}
		return $out;
	}

	public static function upsert( int $id, array $fields ): void {
		global $wpdb;
		$t = self::table();
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT product_id FROM {$t} WHERE product_id = %d", $id ) ) ) {
			$wpdb->update( $t, $fields, [ 'product_id' => $id ] );
		} else {
			$wpdb->insert( $t, [ 'product_id' => $id ] + $fields );
		}
	}

	/* ── Refresh ────────────────────────────────────────────────────────── */

	/**
	 * Bring description rows up to date for these products: a person's
	 * decision (approved/rejected) and an in-flight or finished AI pick are
	 * kept; otherwise the pattern pick is taken if there is one, else the
	 * product waits for Claude (copy exists) or for its vendor page (none).
	 *
	 * @return array<string,int> counts by resulting status
	 */
	public static function refresh( array $ids ): array {
		$rows   = self::get_many( $ids );
		$counts = [];
		// Title descriptions are for digital products only. A physical product (a stale row,
		// or one whose vendor page just got fetched) is parked, never sent to Claude.
		$digital = array_filter( Exclusions::digital( $ids ) );
		foreach ( array_diff( array_map( 'intval', $ids ), array_keys( $digital ) ) as $id ) {
			$row = $rows[ $id ] ?? null;
			if ( $row && ! in_array( $row['status'], [ 'approved', 'rejected', 'skipped' ], true ) ) {
				self::upsert( $id, [ 'status' => 'skipped', 'phrase' => '', 'note' => 'not a digital product' ] );
				$counts['skipped'] = ( $counts['skipped'] ?? 0 ) + 1;
			}
		}
		// Excluded products (Rules tab) are parked the same way and never sent to Claude.
		foreach ( array_diff( array_keys( $digital ), Exclusions::filter( array_keys( $digital ) ) ) as $id ) {
			$row = $rows[ $id ] ?? null;
			if ( $row && ! in_array( $row['status'], [ 'approved', 'rejected', 'skipped' ], true ) ) {
				self::upsert( $id, [ 'status' => 'skipped', 'note' => 'excluded from Product Titles' ] );
				$counts['skipped'] = ( $counts['skipped'] ?? 0 ) + 1;
			}
		}
		$ids = Exclusions::filter( array_keys( $digital ) );
		foreach ( self::inputs( $ids ) as $id => $in ) {
			$row = $rows[ $id ] ?? null;
			if ( $row && ( in_array( $row['status'], [ 'approved', 'rejected', 'ai_queued' ], true ) || ( in_array( $row['method'], [ 'ai', 'manual' ], true ) && in_array( $row['status'], [ 'candidate', 'none' ], true ) ) ) ) {
				$counts[ $row['status'] ] = ( $counts[ $row['status'] ] ?? 0 ) + 1;
				continue;
			}
			// Claude picks for every product with copy (Picker). The pattern pick is kept in
			// the note so a reviewer can compare — measured 2026-09-25, it alone was too
			// noisy for titles ("ultimate tool", half noun phrases).
			$pick   = self::pick_by_pattern( $in['title'], $in['brand'], $in['blocks'] );
			$fields = [ 'status' => $in['blocks'] ? 'needs_ai' : 'pending', 'phrase' => '', 'method' => '', 'note' => $pick ? mb_substr( 'pattern: ' . $pick['phrase'], 0, 250 ) : '' ];
			self::upsert( $id, $fields );
			$counts[ $fields['status'] ] = ( $counts[ $fields['status'] ] ?? 0 ) + 1;
		}
		return $counts;
	}

	/* ── Copy blocks ────────────────────────────────────────────────────── */

	/**
	 * Every copy block a description could come from, most trusted first.
	 *
	 * @param array $local ['web_assets' => …, 'post_content' => …, 'description_scrub' => …, 'xchange_descript' => …]
	 * @return array<int,array{type:string,url:string,text:string}>
	 */
	public static function copy_blocks( ?array $row, array $local ): array {
		$blocks = [];
		if ( $row && trim( (string) $row['vendor_copy'] ) !== '' ) {
			$blocks[] = [ 'type' => 'vendor_page', 'url' => (string) $row['vendor_url'], 'text' => (string) $row['vendor_copy'] ];
		}
		foreach ( [ 'web_assets', 'post_content', 'xchange_descript' ] as $type ) {
			$text = self::plain( (string) ( $local[ $type ] ?? '' ) );
			if ( $type === 'post_content' && self::ai_written( $text ) ) {
				continue;
			}
			if ( $text !== '' ) {
				$blocks[] = [ 'type' => $type, 'url' => '', 'text' => mb_substr( $text, 0, self::COPY_CHARS ) ];
			}
		}
		return $blocks;
	}

	/** Store copy written as generated marketing text (AI_COPY). */
	public static function ai_written( string $text ): bool {
		return (bool) preg_match( self::AI_COPY, $text );
	}

	/**
	 * Vendor copy a person supplied, for a vendor site that blocks the fetcher
	 * (a maker's site answering the store's crawler with HTTP 403). Stored as the
	 * product's vendor_page copy with fetch_status `manual`, so VendorFetcher
	 * never overwrites it and refresh() keeps the pick. The phrase must still
	 * occur word-for-word in the copy; the product-kind head-noun rule is not
	 * applied and up to MANUAL_MAX_WORDS words are allowed (a person chose it:
	 * "Advanced editing for professional editors").
	 *
	 * @return array{set:int[],refused:array<int,string>}
	 */
	public static function set_manual( array $ids, string $url, string $copy, string $phrase, string $source_type = 'vendor_page' ): array {
		if ( ! isset( self::SOURCES[ $source_type ] ) ) {
			return [ 'set' => [], 'refused' => [ 0 => 'unknown source type ' . $source_type ] ];
		}
		$phrase = self::strip_article( trim( $phrase ) );
		$out    = [ 'set' => [], 'refused' => [] ];
		if ( ! self::verify( $phrase, $copy ) ) {
			$out['refused'][0] = 'phrase not found word-for-word in the copy';
			return $out;
		}
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$title = html_entity_decode( (string) get_the_title( $id ), ENT_QUOTES, 'UTF-8' );
			if ( $title === '' ) {
				$out['refused'][ $id ] = 'no such product';
				continue;
			}
			if ( ! self::acceptable( $phrase, $title, false, self::MANUAL_MAX_WORDS ) ) {
				$out['refused'][ $id ] = 'guard refuses the phrase for this title';
				continue;
			}
			// Only vendor-page copy is stored as the product's vendor copy; copy from another
			// source (XChange vendor copy, our store copy) is kept as the pick's excerpt only.
			$vendor = $source_type === 'vendor_page' ? [ 'vendor_url' => $url, 'vendor_copy' => $copy, 'fetch_status' => 'manual', 'fetched_at' => current_time( 'mysql', true ) ] : [];
			self::upsert(
				$id,
				$vendor + [
					'status'       => 'candidate',
					'phrase'       => mb_substr( $phrase, 0, 120 ),
					'method'       => 'manual',
					'source_type'  => $source_type,
					'source_url'   => mb_substr( $url, 0, 500 ),
					'excerpt'      => $copy,
					'picked_at'    => current_time( 'mysql' ),
					'note'         => 'vendor copy entered by hand',
				]
			);
			$out['set'][] = $id;
		}
		return $out;
	}

	/** "the vendor page https://…", "our store copy (not the vendor's)", … */
	public static function source_label( array $row ): string {
		$type  = (string) $row['source_type'];
		$label = self::SOURCE_LABELS[ $type ] ?? str_replace( '_', ' ', $type );
		return $type === 'vendor_page' && (string) $row['source_url'] !== '' ? $label . ' ' . $row['source_url'] : $label;
	}

	/**
	 * Picks taken from AI-written store copy go back for a re-pick from the
	 * remaining sources (or wait for a vendor page when there are none).
	 * No Claude calls here; the picker cron re-picks them. Idempotent.
	 *
	 * @return array{checked:int,dropped:int,refreshed:array}
	 */
	public static function drop_ai_store_copy(): array {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT product_id, excerpt FROM {$t} WHERE source_type = 'post_content' AND status IN ('candidate','none')", ARRAY_A );
		$ids  = [];
		foreach ( $rows as $r ) {
			if ( self::ai_written( self::plain( (string) $r['excerpt'] ) ) ) {
				$ids[] = (int) $r['product_id'];
			}
		}
		foreach ( $ids as $id ) {
			self::upsert( $id, [ 'status' => 'needs_ai', 'phrase' => '', 'method' => '', 'source_type' => '', 'source_url' => '', 'excerpt' => '', 'note' => 'store copy is AI-written; re-pick' ] );
		}
		$counts = [];
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			foreach ( self::refresh( $chunk ) as $k => $n ) {
				$counts[ $k ] = ( $counts[ $k ] ?? 0 ) + $n;
			}
		}
		return [ 'checked' => count( $rows ), 'dropped' => count( $ids ), 'refreshed' => $counts ];
	}

	/**
	 * One description per product family. For each of $titles' products that
	 * belongs to a Product Paths family with other SKUs, the family's best
	 * phrase: one a person approved, else the most trusted source (SOURCES),
	 * else the phrase most SKUs already have. A product keeps its own phrase
	 * when a person approved it, rejected the family's, or the family phrase
	 * fails acceptable() against its own title.
	 *
	 * @param array<int,string> $titles product id => title
	 * @param array<int,array>  $descs  product id => description row (as get_many)
	 * @return array<int,array{row:array,votes:int,members:int,family:string}>
	 */
	public static function family_shared( array $titles, array $descs ): array {
		global $wpdb;
		if ( ! $titles ) {
			return [];
		}
		// Which products are one family's tier, and every published member (filter mmi_pipeline_product_families;
		// this site: Product Paths' offers). Nothing attached: no family shares a description.
		$got     = (array) apply_filters( 'mmi_pipeline_product_families', [ 'of' => [], 'members' => [], 'all' => [] ], array_keys( $titles ) );
		$fam     = (array) ( $got['of'] ?? [] );
		$members = (array) ( $got['members'] ?? [] );
		$all     = array_map( 'intval', (array) ( $got['all'] ?? [] ) );
		if ( ! $fam ) {
			return [];
		}
		$missing = array_diff( $all, array_keys( $descs ) );
		$descs  += $missing ? self::get_many( $missing ) : [];
		$norm    = static fn( string $p ): string => trim( preg_replace( '/[^a-z0-9]+/', ' ', strtolower( $p ) ) );

		$winners = [];
		foreach ( $members as $fid => $list ) {
			$name = '';
			foreach ( $fam as $x ) {
				if ( $x['id'] === $fid ) {
					$name = $x['name'];
					break;
				}
			}
			$addon_family = (bool) preg_match( self::ADDON_RX, $name );
			$list         = array_filter( $list, static fn( $t ) => $addon_family || ! preg_match( self::ADDON_RX, $t ) );
			if ( count( $list ) < 2 ) {
				continue;
			}
			$groups = [];
			foreach ( array_keys( $list ) as $pid ) {
				$d = $descs[ $pid ] ?? null;
				if ( ! $d || ! in_array( $d['status'], [ 'candidate', 'approved' ], true ) || trim( (string) $d['phrase'] ) === '' ) {
					continue;
				}
				$k    = $norm( (string) $d['phrase'] );
				$rank = self::SOURCES[ $d['source_type'] ] ?? 9;
				$g    = $groups[ $k ] ?? [ 'votes' => 0, 'approved' => false, 'manual' => false, 'rank' => 99, 'row' => null ];
				$g['votes']++;
				$g['approved'] = $g['approved'] || $d['status'] === 'approved';
				$g['manual']   = $g['manual'] || $d['method'] === 'manual';
				if ( $rank < $g['rank'] || ( $d['status'] === 'approved' && ( $g['row']['status'] ?? '' ) !== 'approved' ) ) {
					$g['rank'] = min( $g['rank'], $rank );
					$g['row']  = $d;
				}
				$groups[ $k ] = $g;
			}
			if ( ! $groups ) {
				continue;
			}
			uasort(
				$groups,
				static fn( $a, $b ) => [ ! $a['approved'], ! $a['manual'], $a['rank'], -$a['votes'], mb_strlen( $a['row']['phrase'] ), (int) $a['row']['product_id'] ]
					<=> [ ! $b['approved'], ! $b['manual'], $b['rank'], -$b['votes'], mb_strlen( $b['row']['phrase'] ), (int) $b['row']['product_id'] ]
			);
			$winners[ $fid ] = [ 'group' => reset( $groups ), 'key' => array_key_first( $groups ), 'members' => $list, 'family' => $name ];
		}

		$out = [];
		foreach ( $titles as $id => $title ) {
			$w = isset( $fam[ $id ] ) ? ( $winners[ $fam[ $id ]['id'] ] ?? null ) : null;
			if ( ! $w || ! isset( $w['members'][ $id ] ) ) {
				continue;
			}
			$own = $descs[ $id ] ?? null;
			if ( $own && ( $own['status'] === 'approved' || ( $own['method'] === 'manual' && $own['status'] === 'candidate' ) || ( $own['status'] === 'rejected' && $norm( (string) $own['phrase'] ) === $w['key'] ) ) ) {
				continue;
			}
			// A phrase this product's own name already says ("Huddle Collaboration Tool" + "collaboration tools")
			// would only repeat it; the composer would add it and flag descriptor_repeats_name.
			$phrase = (string) $w['group']['row']['phrase'];
			$manual = $w['group']['row']['method'] === 'manual';
			if ( ! self::acceptable( $phrase, (string) $title, ! $manual, $manual ? self::MANUAL_MAX_WORDS : self::MAX_WORDS ) || in_array( (string) strtok( $w['key'], ' ' ), self::words( (string) $title ), true ) ) {
				continue;
			}
			$out[ $id ] = [ 'row' => $w['group']['row'], 'votes' => $w['group']['votes'], 'members' => count( $w['members'] ), 'family' => $w['family'] ];
		}
		return $out;
	}

	/**
	 * XChange web-assets long_description comes as labelled lines —
	 * "  heading: X, \n  paragraph: Y, \n  list: A, B, C" — or as plain prose.
	 *
	 * @return array<int,array{type:string,text:string}>
	 */
	public static function web_assets_blocks( string $raw ): array {
		if ( ! preg_match( '/^\s*(heading|paragraph|list):/m', $raw ) ) {
			$raw = "paragraph: {$raw}";
		}
		$parts  = preg_split( '/^\s*(heading|paragraph|list):\s*/m', $raw, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$blocks = [];
		for ( $i = 0; $i < count( $parts ) - 1; $i++ ) {
			if ( in_array( $parts[ $i ], [ 'heading', 'paragraph', 'list' ], true ) ) {
				$text = trim( preg_replace( '/,\s*$/', '', trim( $parts[ $i + 1 ] ) ) );
				$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $text !== '' ) {
					$blocks[] = [ 'type' => $parts[ $i ], 'text' => $text ];
				}
				$i++;
			}
		}
		return $blocks;
	}

	/** Web-assets text with the labels gone, for picking descriptions. */
	public static function web_assets_text( string $raw ): string {
		return self::plain( implode( '. ', array_column( self::web_assets_blocks( $raw ), 'text' ) ) );
	}

	public static function plain( string $html ): string {
		$html = (string) preg_replace( '#<br\s*/?>|</(?:p|li|h[1-6]|div|tr|td|th|dt|dd|blockquote|ul|ol|section|figcaption)>#i', '$0 ', $html ); // "<li>a</li><li>b</li>" reads "a b", not "ab"
		$s    = html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	/* ── Selection ──────────────────────────────────────────────────────── */

	/**
	 * "<Product> is a <phrase>," in the first ~900 characters of a block,
	 * where <Product> shares a word with the product's own name — so
	 * "the WING is a powerful digital console" is never used for the WING
	 * *training course*. XChange one-liners are used whole when they read
	 * as a description rather than the name again.
	 *
	 * @return array{phrase:string,type:string,url:string,excerpt:string}|null
	 */
	public static function pick_by_pattern( string $title, string $brand, array $blocks ): ?array {
		// The product's own name: what precedes "for <host>" ("Radion Collection for Pigments"),
		// minus offer/term words. A product named just for its brand (MasterWriter) may match the brand.
		$own        = preg_split( '/\s+(for|compatible with)\s+/i', $title, 2 )[0];
		$offer      = [ 'the', 'a', 'an', 'download', 'and', 'of', 'v', 'upgrade', 'crossgrade', 'update', 'edu', 'loyalty', 'offer', 'sub', 'subscription', 'renewal', 'renew', 'new', 'license', 'perpetual', 'year', 'yr', '1yr', '2yr', '3yr', 'month' ];
		$name_words = array_diff( self::words( $own ), self::words( $brand ), $offer );
		if ( ! array_filter( $name_words, static fn( $w ) => ! ctype_digit( $w ) ) ) {
			$name_words = array_diff( self::words( $own ), $offer );
		}
		$kind = self::product_kind( $title );
		foreach ( $blocks as $b ) {
			if ( $b['type'] === 'xchange_descript' ) {
				$t = self::trim_opinion( rtrim( trim( $b['text'] ), '.!' ) );
				if ( self::acceptable( $t, $title ) && array_diff( self::words( $t ), self::words( $title ), self::words( $brand ), [ 'plug', 'in', 'plugin', 'the' ] ) ) {
					return [ 'phrase' => $t, 'type' => $b['type'], 'url' => '', 'excerpt' => $b['text'] ];
				}
				continue;
			}
			$scan = mb_substr( $b['text'], 0, 900 );
			if ( ! preg_match_all( '/((?:[\p{L}\p{N}’\'&+\-]+\s+){1,6})(?:is|are)\s+(?:a|an|the)\s+([^.!?]{3,160})/u', $scan, $mm, PREG_SET_ORDER ) ) {
				continue;
			}
			foreach ( $mm as $m ) {
				if ( ! array_intersect( self::words( $m[1] ), $name_words ) ) {
					continue;
				}
				// Up to the first clause break, then the longest prefix that ends in what the
				// product is: "virtual library made from…" → "virtual library".
				$clause = trim( preg_split( self::STOP, $m[2], 2 )[0] );
				$words  = preg_split( '/\s+/u', $clause );
				for ( $k = min( self::MAX_WORDS, count( $words ) ); $k >= self::MIN_WORDS; $k-- ) {
					$phrase = self::trim_opinion( trim( implode( ' ', array_slice( $words, 0, $k ) ), " \"'“”,." ) );
					if ( self::acceptable( $phrase, $title ) && ( $kind === '' || preg_match( self::KINDS[ $kind ], $phrase ) ) ) {
						return [ 'phrase' => $phrase, 'type' => $b['type'], 'url' => $b['url'], 'excerpt' => mb_substr( $b['text'], 0, self::COPY_CHARS ) ];
					}
				}
			}
		}
		return null;
	}

	/**
	 * 2–6 words ending in what the product is; not the vendor's own voice
	 * ("our", "you"), not praise ("best-selling", "most awarded"), not a vague
	 * frame ("the power of", "version of", "suite of two"), and not the
	 * product's name again ("Stonegrabber D-323 … Expansion Pack").
	 */
	/** "a virtual piano" → "virtual piano": a leading article is dropped, not a reason to reject. */
	public static function strip_article( string $phrase ): string {
		return trim( preg_replace( '/^(a|an|the)\s+/i', '', trim( $phrase ) ) );
	}

	/**
	 * Every pick must end on a product-kind noun (HEAD_NOUNS, which accepts
	 * "-ing" heads like "pitch matching" and "multitrack recording") and must
	 * not open like a sentence fragment ("for Logic EXS", "Built for
	 * mastering", "new pack for AIR") or name hardware. Measured 2026-09-26:
	 * dropping the head rule for Claude's picks let those fragments through.
	 */
	public static function acceptable( string $phrase, string $title = '', bool $require_head = true, int $max_words = self::MAX_WORDS ): bool {
		$words = preg_split( '/\s+/u', trim( $phrase ) );
		$n     = count( $words );
		if ( $n < self::MIN_WORDS || $n > $max_words
			|| preg_match( '/[!?:;©®™…]|\.\.\./u', $phrase )
			|| ( $require_head && ! preg_match( '/\b(' . self::HEAD_NOUNS . ')$/iu', $phrase ) )
			|| preg_match( '/^(for|with|built|new|designed|made|add|get|use|create|make|turn|transparency|windows|mac|ios)\b/i', $phrase )
			|| preg_match( self::HARDWARE_RX, $phrase ) || preg_match( '/\b(hardware|usb|remote)\b/i', $phrase )
			|| preg_match( '/\(|\d+\/\d+-bit/i', $phrase )
			|| preg_match( '/^(the|a|an|this|these|is|are|was|it|its)\b/i', $phrase ) // "Is an Equalizer Plug-In" is a sentence fragment
			|| preg_match( '/\b(our|we|you|your|us|includes?|including|contains?|version of|power of|in one|all of|one of|that|this|best|most|world\W?s|greatest|#1|number one|only|ultimate|groundbreaking|industry-leading|crafted|unique|characterful|innovative|revolutionary new|cutting-edge|comprehensive|high-quality|amazing|awesome|incredible|incredibly|renowned|award-?winning|acclaimed|legendary|iconic|famous|celebrated|stunning|extremely|world-class|state-of-the-art)\b/i', $phrase )
			|| self::has_opinion( $phrase )
			|| preg_match( '/^(over\s+)?\d[\d.,]*\s*(gb|mb)?\s+\w+$/i', $phrase )
			|| preg_match( '/\b(of|and)\s+(two|three|four|five|\d+)\s+\w+$/i', $phrase ) ) {
			return false;
		}
		// Only generic words ("virtual instrument plug-in", "collection of presets") says nothing specific.
		$bland = [ 'virtual', 'instrument', 'instruments', 'plug', 'in', 'plugin', 'plugins', 'software', 'collection', 'of', 'presets', 'library', 'libraries', 'tool', 'tools', 'effect', 'effects', 'bundle', 'pack', 'sounds', 'sound', 'professional', 'powerful', 'new', 'and', 'audio', 'music', 'application', 'app' ];
		if ( ! array_diff( self::words( $phrase ), $bland ) ) {
			return false;
		}
		if ( $title !== '' ) {
			$generic = array_flip( [ 'plugin', 'plug', 'in', 'library', 'pack', 'bundle', 'collection', 'expansion', 'synth', 'synthesizer', 'reverb', 'delay', 'piano', 'drums', 'bass', 'guitar', 'strings', 'eq', 'compressor', 'virtual', 'instrument', 'and', 'the', 'of', 'for' ] );
			$shared  = array_filter( array_intersect( self::words( $phrase ), self::words( $title ) ), static fn( $w ) => ! isset( $generic[ $w ] ) && ! ctype_digit( $w ) );
			if ( count( array_unique( $shared ) ) >= 2 || self::adds_nothing( $phrase, $title ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * The guarantee behind "taken directly from the webpage copy": the phrase
	 * must occur in the copy block (case- and whitespace-insensitive).
	 */
	public static function verify( string $phrase, string $copy ): bool {
		$norm = static fn( string $s ): string => ' ' . preg_replace( '/[^\p{L}\p{N}]+/u', ' ', mb_strtolower( $s ) ) . ' ';
		$p    = trim( $norm( $phrase ) );
		return $p !== '' && mb_strpos( $norm( $copy ), ' ' . $p . ' ' ) !== false;
	}

	/**
	 * Title case for use inside a title ("revolutionary algorithmic reverb"
	 * → "Revolutionary Algorithmic Reverb"). Words the vendor already cased
	 * (EQ, SoundID, 4-band) stay as written.
	 */
	public static function title_case( string $phrase ): string {
		$minor = [ 'a', 'an', 'and', 'or', 'of', 'for', 'the', 'to', 'in', 'on', 'with' ];
		$words = preg_split( '/\s+/u', trim( $phrase ) );
		foreach ( $words as $i => $w ) {
			// "Multi-effect" is sentence case, not vendor styling: it becomes "Multi-Effect" below (user 2026-10-01).
			$sentence_hyphen = (bool) preg_match( '/^[^\p{L}\p{N}]*\p{Lu}\p{Ll}*(?:-\p{Ll}+)+[^\p{L}\p{N}]*$/u', $w ); // not "Mad-in-Italy" (styling)
			if ( $w !== mb_strtolower( $w ) && ! $sentence_hyphen && ! preg_match( '/\/\p{Ll}/u', $w ) ) {
				continue; // vendor casing kept ("EQ", "SoundID")
			}
			if ( $i > 0 && in_array( $w, $minor, true ) ) {
				continue;
			}
			// A word opening with a quote or bracket is still a word: "glue" → "Glue", “pink → “Pink.
			$words[ $i ] = preg_replace_callback( '/(^[^\p{L}\p{N}]*|[-\/])(\p{Ll})/u', static fn( $m ) => $m[1] . mb_strtoupper( $m[2] ), $w );
		}
		return implode( ' ', $words );
	}

	/**
	 * Opinion, not fact (user 2026-10-01: "Waves Tony Maserati Signature Series User-Friendly
	 * Plugins"). A title says what the product is; how good it is belongs in the copy. Sound
	 * character (warm, lush, punchy, vintage) and "Professional" (a maker's "Professional Loudness
	 * Control", 2026-09-28) are facts about the product and stay.
	 */
	const OPINION = 'user-?friendly|easy(?:[- ]to[- ](?:use|play))?|intuitive|versatile|flexible|premium|pro-level|professional-grade|powerful|must-have|perfect|superb|great|fantastic|exceptional|outstanding|unparalleled|unmatched|essential|inspiring|inspirational|beautiful|gorgeous|magical|massive|huge|sweet|creative|musical|simple|effective|expertly|stellar|top-notch|elegant|classy|colorful|efficient|practical|handy|impressive|remarkable|excellent|superior|brilliant|fabulous|wonderful|lovely|ideal';

	/**
	 * Drop opinion words from the front: "versatile parametric EQ plugin" → "parametric EQ plugin",
	 * "powerful yet easy-to-use plugin" → "plugin". What is left is still a verbatim span.
	 */
	public static function trim_opinion( string $phrase ): string {
		$rx = '/^(?:' . self::OPINION . ')(?![\w-])(?:\s*,|\s+(?:and|yet|but|&))?\s+/iu';
		do {
			$before = $phrase;
			$phrase = self::strip_article( (string) preg_replace( $rx, '', $phrase ) );
		} while ( $phrase !== $before );
		return $phrase;
	}

	/** An opinion word anywhere ("Set of 8 intuitive plugins"): not trimmable, so not usable. */
	public static function has_opinion( string $phrase ): bool {
		return (bool) preg_match( '/(?<![\w-])(?:' . self::OPINION . ')(?![\w-])/iu', $phrase );
	}

	/** Words a description can carry without saying anything: the format, not the product. */
	const FILLER = [ 'plugin', 'software', 'audio', 'app', 'application', 'and', 'of', 'the', 'a', 'an', 'for', 'with', 'version', 'vst', 'au', 'aax' ];

	/** Spellings of one product kind, so "Equalizer Plug-In" repeats "EQs" (user 2026-10-01). */
	const SAME_WORD = [ 'equalizer' => 'eq', 'equaliser' => 'eq', 'synthesizer' => 'synth', 'synthesiser' => 'synth', 'effect' => 'fx', 'compressor' => 'comp', 'plug' => 'plugin' ];

	/**
	 * True when every word of the description is already in the title, or is
	 * filler: "Waves PuigTec EQs" + "EQ Plugins" (user 2026-10-01). A repeated
	 * type word is fine when something new comes with it (user 2026-09-28:
	 * "Acme Limiter" + "Professional Loudness Control Plug-In").
	 */
	public static function adds_nothing( string $phrase, string $title ): bool {
		$key = static function ( string $s ): array {
			$s   = preg_replace( '/\bplug-?ins?\b/i', 'plugin', $s );
			$out = [];
			foreach ( self::words( $s ) as $w ) {
				if ( strlen( $w ) >= 3 && substr( $w, -1 ) === 's' && substr( $w, -2 ) !== 'ss' ) {
					$w = substr( $w, 0, -1 ); // EQs, plugins, compressors
				}
				$out[] = self::SAME_WORD[ $w ] ?? $w;
			}
			return $out;
		};
		$new = array_diff( $key( $phrase ), $key( $title ), self::FILLER );
		return $phrase !== '' && ! $new;
	}

	private static function words( string $s ): array {
		return array_values( array_filter( preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( $s ) ) ), 'strlen' ) );
	}

	/**
	 * Title, brand and every copy block a description could come from, for
	 * the given products — four batched queries, no per-product lookups.
	 *
	 * @return array<int,array{title:string,brand:string,blocks:array}>
	 */
	public static function inputs( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return [];
		}
		$in    = implode( ',', $ids );
		$posts = $wpdb->get_results( "SELECT ID, post_title, LEFT(post_content, 3000) c FROM {$wpdb->posts} WHERE ID IN ({$in})", ARRAY_A );
		$meta  = [];
		foreach ( $wpdb->get_results( "SELECT post_id, meta_key, meta_value v FROM {$wpdb->postmeta} WHERE post_id IN ({$in}) AND meta_key = '_sku'", ARRAY_A ) as $m ) {
			$meta[ (int) $m['post_id'] ][ $m['meta_key'] ] = $m['v'];
		}
		$brands = [];
		foreach ( $wpdb->get_results( "SELECT tr.object_id o, t.name n FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_brand' JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tr.object_id IN ({$in})", ARRAY_A ) as $b ) {
			$brands[ (int) $b['o'] ] = html_entity_decode( $b['n'], ENT_QUOTES, 'UTF-8' );
		}
		$rows = self::get_many( $ids );
		// The distributors' own copy of each product, by SKU (filter mmi_pipeline_feed_copy;
		// this site: XChange's web assets and feed, from Catalog's caches). Nothing attached: none.
		$skus  = array_values( array_filter( array_map( 'strval', array_column( $meta, '_sku' ) ) ) );
		$feeds = (array) apply_filters( 'mmi_pipeline_feed_copy', [], $skus );
		$out   = [];
		foreach ( $posts as $p ) {
			$id        = (int) $p['ID'];
			$sku       = (string) ( $meta[ $id ]['_sku'] ?? '' );
			$out[ $id ] = [
				'title'  => html_entity_decode( $p['post_title'], ENT_QUOTES, 'UTF-8' ),
				'brand'  => $brands[ $id ] ?? '',
				'blocks' => self::copy_blocks(
					$rows[ $id ] ?? null,
					[
						'web_assets'        => (string) ( $feeds[ $sku ]['web_assets'] ?? '' ),
						'post_content'      => (string) $p['c'],
						'xchange_descript'  => (string) ( $feeds[ $sku ]['xchange_descript'] ?? '' ),
					]
				),
			];
		}
		return $out;
	}
}
