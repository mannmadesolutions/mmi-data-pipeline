<?php
/**
 * Product content coverage: for each product, how complete its images,
 * description, features, system requirements, version, manuals, videos and
 * audio demos are, and whether its proposed vendor page could fill a gap.
 * Shown as a row of status icons in the Vendor pages tab's Content column
 * (owner 2026-10-08: it replaced "Vendor / page says").
 *
 * Product side: ok / warn / missing / na (doesn't apply: requirements,
 * version and audio on a physical product, weight and size on software). Core attributes (images,
 * description, features, requirements) count as missing when empty; extras (version, manual, videos,
 * audio, weight and size) only as a warning. Page side: VendorPage::fields(), the same values a write
 * would use (PRODUCT_DATA_PROGRAM §11).
 *
 * Page side: what the reader finds on the proposed page's stored copy
 * (PageStore), worked out once per page content and kept in its own table,
 * so a list request reads a row instead of parsing ~360 KB of HTML (~70 ms).
 * Pages not read yet are filled within a time budget per request and by
 * `wp mmpt coverage` (also run nightly after the URL passes), which also reads
 * products' own vendor pages for the Vendor pages tab (PageReview). Nothing here
 * fetches a page. A page several products share is read per product, from what
 * ProductPage::own_html() says is its own (its card), never whole (2026-10-22).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Coverage {

	const DB_OPTION  = 'mmpt_offers_db_version';
	const DB_VERSION = '2'; // 2: `description` (the cleaned page description, VendorPage::description_html())
	const BUDGET_S   = 3.0; // page reads per list request
	const MIN_PAGE_DESCRIPTION = 300;
	const OFFERS_VERSION = 19; // 19: screen-reader-only text and WooCommerce price amounts out (VendorPage::dom()); a JSON-LD picture given as an @id reference is the node it names (2026-11-11.11). 18: the gallery reader leaves out other products (VendorPage::other_product()). 17: a video's poster isn't a picture (VendorPage::in_video()).  2: share image no longer hides a page's own pictures; image formats kept. 3: <br><br> paragraphs, tab bars out, description kept. 4: calls to action and vendor store/install parts out, per-site lines left out (2026-10-14). 5: VendorPage::fields(): videos and audio from embeds and links only, manuals, version from structured data, weight and size (2026-10-16). 6: lists of social profile links out. 7: the vendor's own voice, calls to action, prices, breadcrumbs and shop meta lines out (2026-10-21). 8: sale promotions and cart text out. 9: copyright lines and price-widget pieces out. 10: media-player prompts, redirect notices, press and goodies sections out. 11: a page with a SoundCloud player counts what it plays, not its credit links. 12: a meta-description summary is cleaned too; comment forms and Need help out. 13: a description's opening no longer repeats the product's name or a section label (Overview, Summary…); a headline split over two lines is joined (VendorPage::opening()) 14: a site's header, menu and banners built from plain elements are left out by their names (VendorPage::CHROME_NAME), as is program text a broken script leaves and a tab bar's labels. 15: breadcrumbs too. 16: cards found by a product's code; every picture of a card is the product's; bundle price claims left out.

	/** Attribute → [label, dashicon, core, digital only]. Order = icon order. */
	const ATTRS = [
		'images'      => [ 'Images', 'format-image', true, false ],
		'description' => [ 'Description', 'text-page', true, false ],
		'features'    => [ 'Features', 'editor-ul', true, false ],
		'os'          => [ 'System requirements', 'desktop', true, true ],
		'version'     => [ 'Version', 'tag', false, true ], // an extra until products have versions (none on 2026-10-16)
		'manual'      => [ 'Manual', 'book', false, false ],
		'videos'      => [ 'Videos', 'video-alt3', false, false ],
		'audio'       => [ 'Audio demos', 'format-audio', false, true ],
		'size'        => [ 'Weight and size', 'archive', false, false ], // physical products only (product())
	];

	/** Missing core attribute = 2, missing extra or any warning = 1: the sort key. */
	const WEIGHT = [ 'missing_core' => 2, 'other' => 1 ];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_page_offers';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  url_hash char(32) NOT NULL,
  content_hash char(32) NOT NULL DEFAULT '',
  offers varchar(1000) NOT NULL DEFAULT '',
  description mediumtext,
  computed_at datetime DEFAULT NULL,
  PRIMARY KEY  (url_hash)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/* ── Product side ───────────────────────────────────────────────────── */

	/**
	 * @param int[] $ids
	 * @return array<int,array<string,array{0:string,1:string}>> id → attr → [state, detail]
	 */
	public static function products( array $ids ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return [];
		}
		$R   = class_exists( '\MMI_Product_Content_Registry' ) ? '\MMI_Product_Content_Registry' : null;
		$out = [];
		$big = count( $ids ) > 100;
		foreach ( array_chunk( $ids, 100 ) as $chunk ) {
			_prime_post_caches( $chunk, false, true );
			$dig = Exclusions::digital( $chunk );
			foreach ( $chunk as $id ) {
				$digital    = ! empty( $dig[ $id ] );
				$out[ $id ] = self::product( $id, $digital, $R );
			}
			if ( $big ) {
				// A whole-queue sort mustn't keep 1,000+ products' meta (~0.1 MB each) in memory.
				foreach ( $chunk as $id ) {
					wp_cache_delete( $id, 'post_meta' );
					wp_cache_delete( $id, 'posts' );
					$R && $R::forget( $id );
				}
			}
		}
		return $out;
	}

	/**
	 * Gap scores for sorting a whole queue, cached 15 minutes per product so
	 * re-sorting while reviewing doesn't redo ~2 s of reads.
	 *
	 * @param int[] $ids
	 * @return array<int,int>
	 */
	public static function scores( array $ids ): array {
		$key   = 'mmpt_coverage_scores';
		$cache = get_transient( $key );
		$cache = is_array( $cache ) ? $cache : [];
		$now   = time();
		$need  = array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => ! isset( $cache[ $id ] ) || $cache[ $id ][1] < $now - 15 * MINUTE_IN_SECONDS ) );
		foreach ( self::products( $need ) as $id => $states ) {
			$cache[ $id ] = [ self::gap_score( $states ), $now ];
		}
		if ( $need ) {
			$cache = array_filter( $cache, static fn( $v ) => $v[1] >= $now - 15 * MINUTE_IN_SECONDS );
			set_transient( $key, $cache, 15 * MINUTE_IN_SECONDS );
		}
		$out = [];
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = (int) ( $cache[ (int) $id ][0] ?? 0 );
		}
		return $out;
	}

	private static function product( int $id, bool $digital, ?string $R ): array {
		$count = static fn( string $concept ) => $R && $R::exists( $concept ) ? $R::count( $id, $concept ) : 0;
		$s     = [];

		$thumb   = (int) get_post_meta( $id, '_thumbnail_id', true );
		$gallery = array_filter( array_map( 'intval', explode( ',', (string) get_post_meta( $id, '_product_image_gallery', true ) ) ) );
		$images  = count( array_unique( array_merge( $thumb ? [ $thumb ] : [], $gallery ) ) );
		$s['images'] = $images === 0 ? [ 'missing', 'No images' ] : ( $images === 1 ? [ 'warn', 'One image, no gallery' ] : [ 'ok', $images . ' images' ] );

		$chars = mb_strlen( trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) get_post_field( 'post_content', $id ) ) ) ) );
		$min   = defined( '\MannMade\DataPipeline\Workbench\Health_Checks::MIN_DESCRIPTION' ) ? \MannMade\DataPipeline\Workbench\Health_Checks::MIN_DESCRIPTION : 200; // "thin" = the Workbench health check's threshold
		$s['description'] = $chars === 0 ? [ 'missing', 'No description' ] : ( $chars < $min ? [ 'warn', sprintf( 'Thin: %d characters', $chars ) ] : [ 'ok', number_format( $chars ) . ' characters' ] );

		$n = $count( 'features' );
		$s['features'] = $n === 0 ? [ 'missing', 'No features' ] : ( $n < 3 ? [ 'warn', sprintf( 'Only %d feature%s', $n, $n === 1 ? '' : 's' ) ] : [ 'ok', $n . ' features' ] );

		if ( ! $digital ) {
			$s['os']      = [ 'na', 'Not software' ];
			$s['version'] = [ 'na', 'Not software' ];
		} else {
			$have = [];
			$old  = [];
			foreach ( [ 'mac' => 'requirements_mac', 'windows' => 'requirements_windows' ] as $os => $concept ) {
				if ( $count( $concept ) > 0 ) {
					$have[] = $os === 'mac' ? 'Mac' : 'Windows';
					$req    = $R ? (array) $R::canonical_value( $id, $concept ) : [];
					if ( $req && class_exists( PageRequirements::class ) && PageRequirements::outdated( $req, $os ) ) {
						$old[] = $os === 'mac' ? 'Mac' : 'Windows';
					}
				}
			}
			$s['os'] = ! $have ? [ 'missing', 'No Mac or Windows requirements' ]
				: ( $old ? [ 'warn', implode( ' and ', $old ) . ' requirements look outdated' ] : [ 'ok', implode( ' and ', $have ) . ' requirements' ] );
			// The registry's version (from the maker's page). Not _product_version: that is WooCommerce's own (11.x).
			$ver          = $R && $R::exists( 'version' ) ? $R::get_text( $id, 'version' ) : '';
			$s['version'] = $ver !== '' ? [ 'ok', 'Version ' . $ver ] : [ 'warn', 'No version' ];
		}

		$n = $count( 'manuals' );
		$s['manual'] = $n ? [ 'ok', $n . ' manual' . ( $n === 1 ? '' : 's' ) ] : [ 'warn', 'No manual' ];
		$n = $count( 'videos' );
		$s['videos'] = $n ? [ 'ok', $n . ' video' . ( $n === 1 ? '' : 's' ) ] : [ 'warn', 'No videos' ];
		if ( ! $digital ) {
			$s['audio'] = [ 'na', 'Not software' ];
			// Weight and size: what shipping needs (WooCommerce's own fields).
			$have = array_filter( [ 'weight' => (float) get_post_meta( $id, '_weight', true ), 'size' => (float) get_post_meta( $id, '_length', true ) * (float) get_post_meta( $id, '_width', true ) * (float) get_post_meta( $id, '_height', true ) ] );
			$s['size'] = count( $have ) === 2 ? [ 'ok', 'Weight and size' ] : ( $have ? [ 'warn', isset( $have['weight'] ) ? 'Weight, no size' : 'Size, no weight' ] : [ 'warn', 'No weight or size' ] );
		} elseif ( ( $n = $count( 'audio' ) ) > 0 ) {
			$s['audio'] = [ 'ok', $n . ' audio demo' . ( $n === 1 ? '' : 's' ) ];
			$s['size']  = [ 'na', 'Software' ];
		} else {
			$s['size'] = [ 'na', 'Software' ];
			// A track or playlist is a demo; a bare profile (soundcloud.com/ujam-instruments, 506 products) is the vendor's channel.
			$sc   = trim( (string) get_post_meta( $id, '_mmi_soundcloud_track_id', true ) );
			$path = trim( (string) wp_parse_url( $sc, PHP_URL_PATH ), '/' );
			$s['audio'] = $sc === '' ? [ 'warn', 'No audio demo' ]
				: ( ctype_digit( $sc ) || preg_match( '~api\.soundcloud\.com/(tracks|playlists)/\d+~', $sc ) || substr_count( $path, '/' ) >= 1 ? [ 'ok', 'Audio demo' ] : [ 'warn', 'Only the vendor’s SoundCloud profile, not a demo' ] );
		}
		return $s;
	}

	/** Sort key: bigger = more missing. */
	public static function gap_score( array $states ): int {
		$score = 0;
		foreach ( $states as $attr => [ $state ] ) {
			if ( $state === 'missing' ) {
				$score += self::ATTRS[ $attr ][2] ? self::WEIGHT['missing_core'] : self::WEIGHT['other'];
			} elseif ( $state === 'warn' ) {
				$score += self::WEIGHT['other'];
			}
		}
		return $score;
	}

	/* ── Page side ──────────────────────────────────────────────────────── */

	/**
	 * What each stored page offers, keyed by URL. null = no readable copy
	 * (not fetched, refused, gone) or not read yet within the time budget.
	 *
	 * A page with `card_of` (a product id) is that product's card on a page several share, keyed url#id.
	 *
	 * @param array<int,array{url:string,status:string,file:string,hash:string,title:string,card_of?:int}> $pages
	 * @return array<string,?array<string,array{0:bool,1:string}>>
	 */
	public static function pages( array $pages, float $budget = self::BUDGET_S ): array {
		self::maybe_install();
		global $wpdb;
		$out    = [];
		$hashes = [];
		foreach ( $pages as $p ) {
			$k = self::page_key( $p );
			if ( $p['url'] !== '' && $p['status'] === 'ok' && $p['file'] !== '' ) {
				$hashes[ md5( $k ) ] = $p;
			}
			$out[ $k ] = null;
		}
		if ( ! $hashes ) {
			return $out;
		}
		$have = [];
		$in   = "'" . implode( "','", array_map( 'esc_sql', array_keys( $hashes ) ) ) . "'";
		foreach ( $wpdb->get_results( 'SELECT url_hash, content_hash, offers FROM ' . self::table() . " WHERE url_hash IN ({$in})", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$have[ $r['url_hash'] ] = $r;
		}
		$start = microtime( true );
		foreach ( $hashes as $hash => $p ) {
			$row = $have[ $hash ] ?? null;
			$o   = $row ? (array) json_decode( (string) $row['offers'], true ) : [];
			// Read again when the page, the reader or the site's confirmed vendor map changed.
			$k   = self::page_key( $p );
			if ( $row && $row['content_hash'] === $p['hash'] && (int) ( $o['v'] ?? 1 ) === self::OFFERS_VERSION && (string) ( $o['m'] ?? '' ) === VendorMaps::stamp( $p['url'] ) && (string) ( $o['t'] ?? '' ) === MapCheck::text_stamp( $p['url'] ) ) {
				$out[ $k ] = self::decode( (string) $row['offers'] );
				continue;
			}
			if ( microtime( true ) - $start > $budget ) {
				continue;
			}
			$offers    = empty( $p['card_of'] ) ? self::read_page( $p['url'], $p['file'], $p['title'] ) : self::read_card( $p );
			$desc      = (string) ( $offers['_description'] ?? '' );
			unset( $offers['_description'] );
			$out[ $k ] = self::decode( (string) wp_json_encode( $offers ) );
			$wpdb->replace( self::table(), [ 'url_hash' => $hash, 'content_hash' => $p['hash'], 'offers' => mb_substr( (string) wp_json_encode( $offers ), 0, 1000 ), 'description' => $desc, 'computed_at' => current_time( 'mysql', true ) ], [ '%s', '%s', '%s', '%s', '%s' ] );
		}
		return $out;
	}

	/** Stored compact offers → attr → [has, detail]. Public: SiteGaps reads a site's pages the same way. */
	public static function decode( string $json ): array {
		$o   = json_decode( $json, true );
		$o   = is_array( $o ) ? $o : [];
		$out = [];
		$img = (int) ( $o['images'] ?? 0 );
		$fmt = array_map( 'strtoupper', (array) ( $o['img_fmt'] ?? [] ) );
		$out['images']      = [ $img > 0, $img ? $img . ' image' . ( $img === 1 ? '' : 's' ) . ( $fmt ? ' (' . implode( ', ', $fmt ) . ')' : '' ) : '' ];
		$chr = (int) ( $o['description'] ?? 0 );
		$out['description'] = [ $chr >= self::MIN_PAGE_DESCRIPTION, $chr ? number_format( $chr ) . ' characters of copy' : '' ];
		$f = (int) ( $o['features'] ?? 0 );
		$out['features']    = [ $f >= 3, $f ? $f . ' bullet points' : '' ];
		$out['os']          = [ ! empty( $o['os'] ), ! empty( $o['os'] ) ? 'a system requirements section' : '' ];
		$v = (string) ( $o['version'] ?? '' );
		$out['version']     = [ $v !== '', $v !== '' ? 'mentions version ' . $v : '' ];
		$m = (int) ( $o['manual'] ?? 0 );
		$out['manual']      = [ $m > 0, $m ? $m . ' manual link' . ( $m === 1 ? '' : 's' ) : '' ];
		$vd = (int) ( $o['videos'] ?? 0 );
		$out['videos']      = [ $vd > 0, $vd ? $vd . ' embedded video' . ( $vd === 1 ? '' : 's' ) : '' ];
		$a = (int) ( $o['audio'] ?? 0 );
		$out['audio']       = [ $a > 0, $a ? $a . ' audio demo' . ( $a === 1 ? '' : 's' ) : '' ];
		$z = (string) ( $o['size'] ?? '' );
		$out['size']        = [ $z !== '', $z !== '' ? $z : '' ];
		if ( ! empty( $o['flags'] ) ) {
			$out['_flags'] = [ true, implode( ', ', (array) $o['flags'] ) ];
		}
		if ( ! empty( $o['no_card'] ) ) {
			$out['_no_card'] = [ true, ProductPage::NO_CARD ];
		}
		return $out;
	}

	/** A page's key in pages()' answer: its URL, or url#id for one product's card. */
	public static function page_key( array $p ): string {
		return empty( $p['card_of'] ) ? (string) $p['url'] : $p['url'] . '#' . (int) $p['card_of'];
	}

	/** One product's card on a page several share, read like a page; `no_card` when none names it. */
	private static function read_card( array $p ): array {
		$html = PageStore::read( (string) $p['file'] );
		$own  = $html === '' ? null : ProductPage::own_html( (int) $p['card_of'], (string) $p['url'], $html );
		if ( $own === null ) {
			return [ 'no_card' => 1, 'read' => 1, 'v' => self::OFFERS_VERSION, 'm' => VendorMaps::stamp( $p['url'] ), 't' => MapCheck::text_stamp( $p['url'] ) ];
		}
		return self::read_html( (string) $p['url'], $own, (string) $p['title'] );
	}

	/** Read one stored page into compact counts. */
	public static function read_page( string $url, string $file, string $product_title ): array { // public: VendorMaps measures sites with it
		$html = PageStore::read( $file );
		return $html === '' ? [] : self::read_html( $url, $html, $product_title );
	}

	/** One page's HTML (or one product's card on a page listing several, SiteGaps) read into compact counts. */
	public static function read_html( string $url, string $html, string $product_title ): array {
		$x     = VendorPage::extract( $html, $url, $product_title );
		$fmt   = [];
		foreach ( (array) $x['images'] as $i ) {
			$ext = strtolower( (string) pathinfo( (string) wp_parse_url( (string) $i['url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$fmt[ $ext === 'jpeg' ? 'jpg' : $ext ] = true;
		}
		unset( $fmt[''] );
		$f      = VendorPage::fields( $html, $url, $x ); // the values a write would use
		$items  = count( $f['features'] );
		$ver    = $f['version'];
		$manual = count( $f['manuals'] );
		$size   = $f['size'];
		$size   = trim( ( $size['weight'] ? $size['weight'][0] . ' ' . $size['weight'][1] : '' ) . ( $size['weight'] && $size['dims'] ? ', ' : '' ) . ( $size['dims'] ? implode( ' × ', array_filter( array_slice( $size['dims'], 0, 3 ) ) ) . ' ' . $size['dims'][3] : '' ) );
		// A confirmed vendor map's parts count too; the generic result stays when it found more.
		$mapped = VendorMaps::extras( $url, $html );
		$items  = max( $items, count( (array) ( $mapped['features'] ?? [] ) ) >= 3 ? count( $mapped['features'] ) : 0 );
		$ver    = $ver !== '' ? $ver : (string) ( $mapped['version'] ?? '' );
		$manual = max( $manual, count( (array) ( $mapped['manual'] ?? [] ) ) );
		return array_filter(
			[
				'images'      => count( (array) $x['images'] ),
				'img_fmt'     => array_keys( $fmt ),
				'description' => (int) $x['text_chars'],
				'features'    => $items,
				'os'          => class_exists( PageRequirements::class ) && PageRequirements::extract( VendorMaps::requirements_html( $url, $html ) ) ? 1 : 0, // a confirmed map's requirements part or link
				'version'     => $ver,
				'manual'      => min( $manual, 20 ),
				'videos'      => min( max( count( $f['videos'] ), count( (array) ( $mapped['videos'] ?? [] ) ) ), 50 ),
				'audio'       => min( count( $f['audio'] ), 50 ),
				'size'        => mb_substr( $size, 0, 60 ),
				'flags'       => array_values( (array) $x['flags'] ),
				'm'           => VendorMaps::stamp( $url ), // read with this confirmed map ('' = none); see pages()
				't'           => MapCheck::text_stamp( $url ), // and the site's lines left out ('' = none, dropped by the filter like 'm')
			]
		) + [ 'read' => 1, 'v' => self::OFFERS_VERSION, '_description' => VendorPage::description_html( $x ) ];
	}

	/**
	 * The cleaned description of a URL's stored page, as read with the
	 * current reader ('' when the page isn't stored or not read yet).
	 * Description proposals use it (Enrichment::compose_content()).
	 */
	public static function description_for( string $url ): string {
		if ( $url === '' ) {
			return '';
		}
		self::maybe_install();
		global $wpdb;
		$stamp = VendorMaps::stamp( $url );
		$text  = MapCheck::text_stamp( $url );
		return (string) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT o.description FROM ' . self::table() . ' o JOIN ' . PageStore::table() . ' pg ON pg.url_hash = o.url_hash AND pg.content_hash = o.content_hash
				 WHERE o.url_hash = %s AND LOCATE( %s, o.offers ) > 0 AND ' . ( $stamp === '' ? 'LOCATE( %s, o.offers ) = 0' : 'LOCATE( %s, o.offers ) > 0' )
				. ' AND ' . ( $text === '' ? 'LOCATE( %s, o.offers ) = 0' : 'LOCATE( %s, o.offers ) > 0' ),
				md5( $url ),
				'"v":' . self::OFFERS_VERSION,
				$stamp === '' ? '"m":"' : '"m":"' . $stamp . '"',
				$text === '' ? '"t":"' : '"t":"' . $text . '"'
			)
		); // a reading made with the site's current confirmed map (or none) and its current lines left out
	}

	/* ── Rows ───────────────────────────────────────────────────────────── */

	/**
	 * Coverage for Vendor URLs rows: attr → {state, label, icon, detail, page}
	 * where page is the proposed page's offer ('' when it has none or can't
	 * be read) and `fills` marks a product gap the page has.
	 *
	 * @param array<int,array<string,mixed>> $rows UrlQueue rows (product_id, url, page_* columns, post_title)
	 * @return array<int,array{attrs:array,page:string}> keyed by product id
	 */
	public static function for_rows( array $rows ): array {
		$ids    = array_map( static fn( $r ) => (int) $r['product_id'], $rows );
		$prod   = self::products( $ids );
		// A page several products share is read per product, from its own card (ProductPage), never whole.
		$units  = array_map(
			static fn( $r ) => [
				'url'     => (string) $r['url'],
				'status'  => (string) ( $r['page_status'] ?? '' ),
				'file'    => (string) ( $r['page_file'] ?? '' ),
				'hash'    => (string) ( $r['page_hash'] ?? '' ),
				'title'   => html_entity_decode( (string) $r['post_title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'card_of' => SharedPages::relation( (int) $r['product_id'], (string) $r['url'] )['kind'] === 'several' ? (int) $r['product_id'] : 0,
			],
			$rows
		);
		$pages  = self::pages( $units );
		$out = [];
		foreach ( $rows as $i => $r ) {
			$id     = (int) $r['product_id'];
			$offers = $pages[ self::page_key( $units[ $i ] ) ] ?? null;
			$attrs  = [];
			foreach ( self::ATTRS as $attr => [ $label, $icon ] ) {
				[ $state, $detail ] = $prod[ $id ][ $attr ] ?? [ 'na', '' ];
				[ $has, $what ]     = $offers[ $attr ] ?? [ false, '' ];
				$attrs[]            = [
					'key'    => $attr,
					'label'  => $label,
					'icon'   => $icon,
					'state'  => $state,
					'detail' => $detail,
					'page'   => $has ? $what : '',
					'fills'  => $has && in_array( $state, [ 'missing', 'warn' ], true ),
				];
			}
			$out[ $id ] = [
				'attrs' => $attrs,
				'score' => self::gap_score( $prod[ $id ] ?? [] ),
				'page'  => $offers === null ? self::page_note( $r ) : ( isset( $offers['_no_card'] ) ? $offers['_no_card'][1] : ( isset( $offers['_flags'] ) && strpos( $offers['_flags'][1], 'page_version_differs' ) !== false ? 'Page is about another version' : '' ) ),
				'no_card' => isset( $offers['_no_card'] ),
				'read'  => $offers !== null,
			];
		}
		return $out;
	}

	/** Why the proposed page has no dots. */
	private static function page_note( array $r ): string {
		$s = (string) ( $r['page_status'] ?? '' );
		if ( (string) $r['url'] === '' ) {
			return '';
		}
		if ( preg_match( '/^http_(404|410)$/', $s ) ) {
			return 'Proposed page is gone (' . substr( $s, 5 ) . ')';
		}
		$moved = [ 'moved' => 'Proposed page has moved to a new address', 'moved_offsite' => 'Proposed page redirects to another site', 'redirect_loop' => 'Proposed page redirects in a loop' ];
		if ( isset( $moved[ $s ] ) ) {
			return $moved[ $s ];
		}
		if ( $s !== '' && $s !== 'ok' ) {
			return 'Proposed page refused or failed (' . str_replace( 'http_', '', $s ) . ')';
		}
		return $s === 'ok' ? 'Proposed page not read yet' : 'Proposed page not fetched yet';
	}

	/**
	 * Read stored proposed pages of open Vendor URLs rows that have no offers
	 * yet (CLI; nightly after the URL passes).
	 */
	public static function precompute( int $max = 2000 ): array {
		self::maybe_install();
		global $wpdb;
		$c    = UrlFinder::table();
		$pg   = PageStore::table();
		$o    = self::table();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.url, pg.status, pg.html_file file, pg.content_hash hash, p.post_title title
				 FROM {$c} c JOIN {$wpdb->posts} p ON p.ID = c.product_id AND p.post_status = 'publish'
				 JOIN {$pg} pg ON pg.url_hash = MD5( c.url ) AND pg.status = 'ok' AND pg.html_file <> ''
				 LEFT JOIN {$o} o ON o.url_hash = pg.url_hash AND o.content_hash = pg.content_hash
				 WHERE c.status = 'candidate' AND ( o.url_hash IS NULL OR LOCATE( %s, o.offers ) = 0 )" . Exclusions::sql_not_excluded( 'c.product_id' ) . '
				 LIMIT %d',
				'"v":' . self::OFFERS_VERSION, // offers read by an older reader are read again
				$max
			),
			ARRAY_A
		);
		// Products' own vendor pages too (Vendor pages tab, 2026-10-08): what the nightly read stored.
		$d    = Descriptions::table();
		$rows = array_merge(
			$rows,
			$wpdb->get_results(
				$wpdb->prepare(
					"SELECT DISTINCT d.vendor_url url, pg.status, pg.html_file file, pg.content_hash hash, p.post_title title
					 FROM {$d} d JOIN {$wpdb->posts} p ON p.ID = d.product_id AND p.post_status = 'publish'
					 JOIN {$pg} pg ON pg.url_hash = MD5( d.vendor_url ) AND pg.status = 'ok' AND pg.html_file <> ''
					 LEFT JOIN {$o} o ON o.url_hash = pg.url_hash AND o.content_hash = pg.content_hash
					 WHERE d.vendor_url <> '' AND ( o.url_hash IS NULL OR LOCATE( %s, o.offers ) = 0 )" . Exclusions::sql_not_excluded( 'd.product_id' ) . '
					 LIMIT %d',
					'"v":' . self::OFFERS_VERSION,
					max( 0, $max - count( $rows ) )
				),
				ARRAY_A
			)
		);
		$start = microtime( true );
		foreach ( array_chunk( $rows, 25 ) as $chunk ) {
			self::pages( array_map( static fn( $r ) => [ 'url' => $r['url'], 'status' => $r['status'], 'file' => $r['file'], 'hash' => $r['hash'], 'title' => html_entity_decode( (string) $r['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ], $chunk ), 600.0 );
			if ( PageStore::server_busy() ) {
				break;
			}
		}
		return [ 'pages' => count( $rows ), 'seconds' => round( microtime( true ) - $start, 1 ) ];
	}
}
