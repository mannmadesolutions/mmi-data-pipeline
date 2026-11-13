<?php
/**
 * Which pictures are the same picture (owner 2026-10-18): vendor pictures are
 * merged into a product's gallery, never swapped for it.
 *
 * Every picture gets Image Trim's fingerprint of its trimmed product area
 * (PictureMath::measure(): a 64-bit difference hash, a colour thumbnail, the real
 * detail). Two pictures are then
 *
 *   same      — at most `dup_distance` bits apart, same hue, same shape;
 *   different — more than DOUBT_BITS apart, another shape or another colour;
 *   in doubt  — in between. The picture runner (WP-CLI) shows the doubtful
 *               pairs of one product to Claude in one call on the owner's
 *               subscription (Claude::run_content(); owner approved up to
 *               CALLS_DAY calls a day on Haiku). Each answer is kept, so a
 *               pair is asked once. Unanswered (over the limit, Claude off,
 *               not sure): the page's picture is not added (owner 2026-10-21:
 *               never a duplicate), and a guess never groups or drops any of
 *               the product's own pictures.
 *
 * merge() then decides each picture: Kept (a current picture; stays as it is unless
 * a Sharper copy takes its place), New (added; no cap since 2026-10-30, owner: "There should be no cap"), Duplicate (a second copy in the current set, by
 * fingerprint or a sure answer; the better copy stays) and Have (a page picture
 * the product already has, or may have). Owner 2026-10-21: "do not replace
 * existing valid images, but do not duplicate them, either"; owner 2026-11-02: "We should strive to obtain the best
 * quality (sharpest, largest file size) images we can from wherever we can and serve the best web-friendly versions
 * of them", and a match is "at the worst … replacing an existing image, not duplicating it": so a page copy with
 * SHARPER times the real detail of the product's copy takes its place (Sharper copy; the runner quarantines the old
 * file and Undo puts it back), and a copy that isn't sharper is one the product has. Web requests (the editor's preview) never call Claude: they
 * use the kept answers and show the rest as "in doubt, not added".
 *
 * A person has the last word (PictureChoices, owner 2026-10-30): given the product, merge() leaves out every copy of a
 * picture a person vetoed for it or for its whole site (Vetoed; one the product has comes off when its pictures are
 * written) and puts the picture a person chose as featured first.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PictureMatch {

	const DB_OPTION   = 'mmpt_picture_pairs_db_version';
	const DB_VERSION  = '1';
	const DOUBT_BITS  = 20;      // fingerprints further apart than this are different pictures (a wider crop of one screenshot: 17)
	const MAX_HUE     = 20;      // PictureMath::hue_distance() of copies (measured 1.7–4.3)
	const VARIANT_HUE = 60;      // above this a colour variant, not a copy (measured 116–168)
	const MAX_SHAPE   = 1.25;    // copies' product areas differ in width:height by at most this factor
	const SHARPER     = 1.15;    // a page copy replaces ours only with this much more real detail
	const CALLS_DAY   = 300;     // Claude calls a day (owner 2026-10-18)
	const CALLS_KEY   = 'mmpt_picture_match_calls';
	const MAX_PAIRS   = 12;      // doubtful pairs shown in one call
	const SEND_EDGE   = 512;     // px, longest side of a picture sent to Claude
	const MODEL       = 'haiku';
	const TIMEOUT_S   = 120;
	const FP_TTL      = 30 * DAY_IN_SECONDS;
	const FP_VERSION  = '2';     // part of the fingerprint cache keys: bump when of_file() changes
	const TRIM_PASSES = 2;       // plain borders trimmed, outermost first
	const TRIM_EDGE   = 400;     // px, the border search works at this size
	const TRIM_TOL    = 24;      // per channel, a border pixel's distance from its corner colour
	const MAX_PIXELS  = 40000000;
	const KINDS       = [ 'kept', 'sharper', 'new', 'duplicate', 'have', 'over_cap', 'unreadable', 'vetoed' ]; // over_cap: older ledger rows (the 10-picture cap, removed 2026-10-30); vetoed: a person's choice (PictureChoices)

	const SYSTEM = 'You compare product pictures for an online store catalog. Two pictures are the SAME picture when they show the same photo or screenshot: the same scene, the same camera angle, the same screen state. Differences only in size, resolution, crop, padding, background colour, compression or a watermark still mean the same picture. They are DIFFERENT when they show another angle, another view or panel, another screen state, preset or room, another product, or another colour of the product. When you cannot tell, say so with sure=false.';

	const SCHEMA = [
		'type'       => 'object',
		'properties' => [
			'pairs' => [
				'type'  => 'array',
				'items' => [
					'type'       => 'object',
					'properties' => [
						'pair' => [ 'type' => 'integer' ],
						'same' => [ 'type' => 'boolean' ],
						'sure' => [ 'type' => 'boolean' ],
						'why'  => [ 'type' => 'string' ],
					],
					'required'   => [ 'pair', 'same', 'sure', 'why' ],
				],
			],
		],
		'required'   => [ 'pairs' ],
	];

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_picture_pairs';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  pair_key char(32) NOT NULL,
  same tinyint(1) NOT NULL DEFAULT 0,
  sure tinyint(1) NOT NULL DEFAULT 0,
  why varchar(255) NOT NULL DEFAULT '',
  bits tinyint(3) unsigned NOT NULL DEFAULT 0,
  asked_at datetime NOT NULL,
  PRIMARY KEY  (pair_key)
) " . $wpdb->get_charset_collate() . ';' );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/* ── Fingerprints ──────────────────────────────────────────────────── */

	/**
	 * A file's fingerprint, or null when it can't be read: PictureMath::profile()
	 * of the picture inside its plain borders, up to TRIM_PASSES deep (a
	 * screenshot on black, padded on white, is the same picture as the
	 * screenshot on black alone).
	 *
	 * @return array{phash:string,thumb:string,content_w:int,content_h:int,eff:int,width:int,height:int,key:string}|null
	 */
	public static function of_file( string $file ): ?array {
		if ( ! class_exists( PictureMath::class ) || ! is_readable( $file ) ) {
			return null;
		}
		$info = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unreadable files are expected
		if ( ! $info || $info[0] * $info[1] > self::MAX_PIXELS ) {
			return null;
		}
		$im = @imagecreatefromstring( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $im ) {
			return null;
		}
		[ $w, $h ]         = [ imagesx( $im ), imagesy( $im ) ];
		[ $x, $y, $cw, $ch ] = self::content_box( $im, $w, $h );
		$p                 = PictureMath::profile( $im, $x, $y, $cw, $ch );
		imagedestroy( $im );
		$fp = [
			'phash'     => (string) $p['phash'],
			'thumb'     => (string) $p['thumb'],
			'content_w' => (int) $p['content_w'],
			'content_h' => (int) $p['content_h'],
			'eff'       => (int) $p['eff'],
			'width'     => $w,
			'height'    => $h,
		];
		return strlen( $fp['phash'] ) === 16 ? $fp + [ 'key' => md5( $fp['phash'] . $fp['thumb'] ) ] : null;
	}

	/**
	 * The picture inside its plain borders: a border is a frame whose four
	 * corners share a colour (transparent counts as white) and whose rows and
	 * columns are all that colour. Up to TRIM_PASSES borders, outermost first.
	 *
	 * @param \GdImage|resource $im
	 * @return int[] x, y, w, h in $im
	 */
	private static function content_box( $im, int $w, int $h ): array {
		$s  = min( 1, self::TRIM_EDGE / max( $w, $h ) );
		$sw = max( 1, (int) round( $w * $s ) );
		$sh = max( 1, (int) round( $h * $s ) );
		$sm = imagecreatetruecolor( $sw, $sh );
		imagefill( $sm, 0, 0, imagecolorallocate( $sm, 255, 255, 255 ) );
		imagecopyresampled( $sm, $im, 0, 0, 0, 0, $sw, $sh, $w, $h ); // flattened on white
		$rgb  = static function ( int $px, int $py ) use ( $sm ) {
			$v = imagecolorat( $sm, $px, $py );
			return [ ( $v >> 16 ) & 255, ( $v >> 8 ) & 255, $v & 255 ];
		};
		$near = static fn( array $a, array $b ) => abs( $a[0] - $b[0] ) <= self::TRIM_TOL && abs( $a[1] - $b[1] ) <= self::TRIM_TOL && abs( $a[2] - $b[2] ) <= self::TRIM_TOL;
		[ $x0, $y0, $x1, $y1 ] = [ 0, 0, $sw - 1, $sh - 1 ];
		for ( $pass = 0; $pass < self::TRIM_PASSES; $pass++ ) {
			$c = $rgb( $x0, $y0 );
			if ( ! $near( $c, $rgb( $x1, $y0 ) ) || ! $near( $c, $rgb( $x0, $y1 ) ) || ! $near( $c, $rgb( $x1, $y1 ) ) ) {
				break;
			}
			$row = static function ( int $py ) use ( $x0, $x1, $rgb, $near, $c ) {
				for ( $px = $x0; $px <= $x1; $px++ ) {
					if ( ! $near( $rgb( $px, $py ), $c ) ) {
						return false;
					}
				}
				return true;
			};
			$col = static function ( int $px, int $ya, int $yb ) use ( $rgb, $near, $c ) {
				for ( $py = $ya; $py <= $yb; $py++ ) {
					if ( ! $near( $rgb( $px, $py ), $c ) ) {
						return false;
					}
				}
				return true;
			};
			[ $a, $b, $l, $r ] = [ $y0, $y1, $x0, $x1 ];
			while ( $a < $b && $row( $a ) ) {
				$a++;
			}
			while ( $b > $a && $row( $b ) ) {
				$b--;
			}
			while ( $l < $r && $col( $l, $a, $b ) ) {
				$l++;
			}
			while ( $r > $l && $col( $r, $a, $b ) ) {
				$r--;
			}
			if ( ( $r - $l + 1 ) * ( $b - $a + 1 ) < 0.05 * $sw * $sh || [ $l, $a, $r, $b ] === [ $x0, $y0, $x1, $y1 ] ) {
				break; // nothing (left) to trim, or it would trim the picture away
			}
			[ $x0, $y0, $x1, $y1 ] = [ $l, $a, $r, $b ];
		}
		imagedestroy( $sm );
		$bx = max( 0, (int) floor( $x0 / $s ) );
		$by = max( 0, (int) floor( $y0 / $s ) );
		return [ $bx, $by, max( 1, min( $w, (int) ceil( ( $x1 + 1 ) / $s ) ) - $bx ), max( 1, min( $h, (int) ceil( ( $y1 + 1 ) / $s ) ) - $by ) ];
	}

	/** An attachment's fingerprint (cached against its file's size and time). */
	public static function of_attachment( int $att ): ?array {
		$file = ImageConvert::zoom_path( $att ) ?: (string) get_attached_file( $att ); // its zoom copy holds the detail it really has (up to ImageConvert::ZOOM)
		if ( $file === '' || ! is_file( $file ) ) {
			return null;
		}
		$ck = 'mmpt_fp' . self::FP_VERSION . '_' . md5( $file . '|' . filesize( $file ) . '|' . filemtime( $file ) );
		$fp = get_transient( $ck );
		if ( ! is_array( $fp ) ) {
			$fp = self::of_file( $file ) ?? [];
			set_transient( $ck, $fp, self::FP_TTL );
		}
		return $fp ?: null;
	}

	/** A vendor picture's fingerprint: downloaded once, cached by URL. */
	public static function of_url( string $url ): ?array {
		$ck = 'mmpt_fpu' . self::FP_VERSION . '_' . md5( $url );
		$fp = get_transient( $ck );
		if ( is_array( $fp ) ) {
			return $fp ?: null;
		}
		$file = self::download( $url );
		$fp   = $file !== '' ? ( self::of_file( $file ) ?? [] ) : [];
		if ( $file !== '' ) {
			wp_delete_file( $file );
		}
		set_transient( $ck, $fp, $file !== '' ? self::FP_TTL : HOUR_IN_SECONDS ); // a failed download is tried again sooner
		return $fp ?: null;
	}

	/**
	 * Fingerprint the page's pictures not cached yet, fetched together (as a
	 * browser showing the page would), so the editor's preview doesn't wait
	 * the page reader's gap between each.
	 */
	public static function prefetch( array $urls ): void {
		$todo = [];
		foreach ( array_unique( array_filter( array_map( 'strval', $urls ) ) ) as $u ) {
			if ( ! is_array( get_transient( 'mmpt_fpu' . self::FP_VERSION . '_' . md5( $u ) ) ) && wp_http_validate_url( $u ) ) {
				$todo[ $u ] = wp_tempnam( 'mmpt-fp' );
			}
		}
		if ( ! $todo || ! class_exists( '\WpOrg\Requests\Requests' ) ) {
			return;
		}
		$reqs = [];
		foreach ( $todo as $u => $tmp ) {
			$reqs[ $u ] = [ 'url' => $u, 'type' => 'GET', 'options' => [ 'filename' => $tmp, 'timeout' => 20, 'connect_timeout' => 10, 'useragent' => FetchPolicy::asset_ua() ] ];
		}
		try {
			// audit-ok: one vendor page's pictures from vendor image hosts, never this server; uncached only
			$res = \WpOrg\Requests\Requests::request_multiple( $reqs );
		} catch ( \Throwable $e ) {
			$res = [];
		}
		foreach ( $todo as $u => $tmp ) {
			$ok = isset( $res[ $u ] ) && $res[ $u ] instanceof \WpOrg\Requests\Response && $res[ $u ]->status_code === 200;
			$fp = $ok ? ( self::of_file( $tmp ) ?? [] ) : [];
			set_transient( 'mmpt_fpu' . self::FP_VERSION . '_' . md5( $u ), $fp, $ok ? self::FP_TTL : HOUR_IN_SECONDS );
			wp_delete_file( $tmp );
		}
	}

	/** A vendor picture in a temporary file (the site's polite gap), or ''. */
	private static function download( string $url ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( $host === '' ) {
			return '';
		}
		if ( class_exists( '\MMI_API_Throttler' ) ) {
			\MMI_API_Throttler::throttle( VendorFetcher::throttle_key( $host ), [ 'min_gap_ms' => PageStore::policy( $host )['gap_ms'] ] );
		}
		$tmp = download_url( $url, 30 );
		return is_wp_error( $tmp ) ? '' : (string) $tmp;
	}

	/* ── Same picture? ─────────────────────────────────────────────────── */

	/** 'same' | 'different' | 'doubt', with the bits apart. @return array{0:string,1:int} */
	public static function relation( array $a, array $b ): array {
		$bits = PictureMath::distance( $a['phash'], $b['phash'] );
		$sa   = $a['content_w'] / max( 1, $a['content_h'] );
		$sb   = $b['content_w'] / max( 1, $b['content_h'] );
		if ( $sa / $sb > self::MAX_SHAPE || $sb / $sa > self::MAX_SHAPE || $bits > self::DOUBT_BITS ) {
			return [ 'different', $bits ];
		}
		$hue = PictureMath::hue_distance( (string) $a['thumb'], (string) $b['thumb'] );
		if ( $hue > self::VARIANT_HUE ) {
			return [ 'different', $bits ];
		}
		$same = (int) apply_filters( 'mmi_pipeline_picture_dup_distance', 6 ); // phash bits two copies may differ by; this site: Image Trim's setting
		return [ $bits <= $same && $hue <= self::MAX_HUE ? 'same' : 'doubt', $bits ];
	}

	private static function pair_key( array $a, array $b ): string {
		$k = [ $a['key'], $b['key'] ];
		sort( $k );
		return md5( implode( '|', $k ) );
	}

	/** Kept answers for these pair keys. @return array<string,array{same:int,sure:int,why:string}> */
	private static function answers( array $keys ): array {
		if ( ! $keys ) {
			return [];
		}
		self::maybe_install();
		global $wpdb;
		$in   = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT pair_key, same, sure, why FROM ' . self::table() . " WHERE pair_key IN ({$in})", $keys ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out  = [];
		foreach ( $rows as $r ) {
			$out[ $r['pair_key'] ] = [ 'same' => (int) $r['same'], 'sure' => (int) $r['sure'], 'why' => (string) $r['why'] ];
		}
		return $out;
	}

	/** Claude calls left today. */
	public static function calls_left(): int {
		$c   = (array) get_option( self::CALLS_KEY, [] );
		$max = Pictures::limits()['calls']; // CALLS_DAY, or a pass's own number (0: no calls at all)
		return ( $c['day'] ?? '' ) === gmdate( 'Y-m-d' ) ? max( 0, $max - (int) $c['n'] ) : $max;
	}

	private static function count_call(): void {
		$c = (array) get_option( self::CALLS_KEY, [] );
		$n = ( $c['day'] ?? '' ) === gmdate( 'Y-m-d' ) ? (int) $c['n'] + 1 : 1;
		update_option( self::CALLS_KEY, [ 'day' => gmdate( 'Y-m-d' ), 'n' => $n ], false );
	}

	/**
	 * Ask Claude about doubtful pairs (WP-CLI only, within the day's calls), keep the answers.
	 *
	 * @param array $pairs each [ fingerprint a, fingerprint b, file a, file b, bits ]
	 * @return int answers kept
	 */
	private static function ask( array $pairs ): int {
		if ( ! $pairs || ! ( defined( 'WP_CLI' ) && WP_CLI ) || self::calls_left() < 1 ) {
			return 0;
		}
		$pairs   = array_slice( $pairs, 0, self::MAX_PAIRS );
		$content = [];
		foreach ( $pairs as $i => $p ) {
			$a = self::block( $p[2] );
			$b = self::block( $p[3] );
			if ( ! $a || ! $b ) {
				continue;
			}
			array_push( $content, [ 'type' => 'text', 'text' => 'Pair ' . ( $i + 1 ) . ', picture A:' ], $a, [ 'type' => 'text', 'text' => 'Pair ' . ( $i + 1 ) . ', picture B:' ], $b );
		}
		if ( ! $content ) {
			return 0;
		}
		$content[] = [ 'type' => 'text', 'text' => 'For each pair: is B the same picture as A? Answer every pair by its number.' ];
		self::count_call();
		$r = \MMI_AI::ask( [ 'system' => self::SYSTEM, 'content' => $content, 'schema' => self::SCHEMA, 'model' => self::MODEL, 'timeout' => self::TIMEOUT_S, 'thinking' => true, 'max_tokens' => 4000, 'purpose' => 'picture-match' ] );
		$r = is_wp_error( $r ) ? [ 'error' => $r->get_error_message() ] : [ 'structured_output' => $r['answer'] ]; // the shape Claude::run_content() gave
		if ( isset( $r['error'] ) ) {
			Module::warn( 'Picture match: Claude call failed; doubtful pairs kept as different', [ 'error' => $r['error'] ] );
			return 0;
		}
		self::maybe_install();
		global $wpdb;
		$n = 0;
		foreach ( (array) ( $r['structured_output']['pairs'] ?? [] ) as $ans ) {
			$p = $pairs[ (int) ( $ans['pair'] ?? 0 ) - 1 ] ?? null;
			if ( ! $p ) {
				continue;
			}
			$wpdb->replace(
				self::table(),
				[
					'pair_key' => self::pair_key( $p[0], $p[1] ),
					'same'     => ! empty( $ans['same'] ) ? 1 : 0,
					'sure'     => ! empty( $ans['sure'] ) ? 1 : 0,
					'why'      => mb_substr( (string) ( $ans['why'] ?? '' ), 0, 255 ),
					'bits'     => (int) $p[4],
					'asked_at' => current_time( 'mysql', true ),
				]
			);
			$n++;
		}
		return $n;
	}

	/** A picture as a base64 JPEG block on white, at most SEND_EDGE px. */
	private static function block( string $file ): ?array {
		$im = is_readable( $file ) ? @imagecreatefromstring( (string) file_get_contents( $file ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $im ) {
			return null;
		}
		$w = imagesx( $im );
		$h = imagesy( $im );
		$s = min( 1, self::SEND_EDGE / max( $w, $h ) );
		$c = imagecreatetruecolor( max( 1, (int) round( $w * $s ) ), max( 1, (int) round( $h * $s ) ) );
		imagefill( $c, 0, 0, imagecolorallocate( $c, 255, 255, 255 ) );
		imagecopyresampled( $c, $im, 0, 0, 0, 0, imagesx( $c ), imagesy( $c ), $w, $h );
		ob_start();
		imagejpeg( $c, null, 80 );
		$data = (string) ob_get_clean();
		imagedestroy( $im );
		imagedestroy( $c );
		return [ 'type' => 'image', 'source' => [ 'type' => 'base64', 'media_type' => 'image/jpeg', 'data' => base64_encode( $data ) ] ];
	}

	/**
	 * Same picture for every pair of these pictures: fingerprints, kept answers,
	 * and (if $ask, from WP-CLI) one Claude call for the doubtful ones.
	 *
	 * @param array $pics  each [ 'fp' => fingerprint|null, 'file' => callable():string ]
	 * @param array $pairs index pairs [ i, j ] to decide
	 * @return array{same:array<string,bool>,bits:array<string,int>,doubt:int,asked:int} keyed "i:j"
	 */
	public static function decide( array $pics, array $pairs, bool $ask ): array {
		$same  = [];
		$bits  = [];
		$doubt = [];
		foreach ( $pairs as [ $i, $j ] ) {
			$a = $pics[ $i ]['fp'] ?? null;
			$b = $pics[ $j ]['fp'] ?? null;
			if ( ! $a || ! $b ) {
				continue;
			}
			[ $rel, $n ]       = self::relation( $a, $b );
			$bits[ "{$i}:{$j}" ] = $n;
			if ( $rel === 'same' ) {
				$same[ "{$i}:{$j}" ] = true;
			} elseif ( $rel === 'doubt' ) {
				$doubt[ "{$i}:{$j}" ] = [ $i, $j, self::pair_key( $a, $b ), $n ];
			}
		}
		$asked = 0;
		$known = self::answers( array_values( array_unique( array_column( $doubt, 2 ) ) ) );
		$open  = array_filter( $doubt, static fn( $d ) => ! isset( $known[ $d[2] ] ) );
		if ( $ask && $open ) {
			usort( $open, static fn( $x, $y ) => $x[3] <=> $y[3] ); // closest first
			$send = [];
			foreach ( array_slice( $open, 0, self::MAX_PAIRS ) as $d ) {
				$fa = ( $pics[ $d[0] ]['file'] )();
				$fb = ( $pics[ $d[1] ]['file'] )();
				if ( $fa !== '' && $fb !== '' ) {
					$send[] = [ $pics[ $d[0] ]['fp'], $pics[ $d[1] ]['fp'], $fa, $fb, $d[3] ];
				}
			}
			$asked = self::ask( $send );
			$known = self::answers( array_values( array_unique( array_column( $doubt, 2 ) ) ) );
		}
		$left = 0;
		$open = []; // still doubtful: no sure answer either way
		foreach ( $doubt as $k => $d ) {
			$ans = $known[ $d[2] ] ?? null;
			if ( $ans && $ans['same'] && $ans['sure'] ) {
				$same[ $k ] = true;
			} elseif ( ! $ans || empty( $ans['sure'] ) ) {
				$open[ $k ] = true;
				$left      += $ans ? 0 : 1;
			}
		}
		return [ 'same' => $same, 'bits' => $bits, 'doubt' => $left, 'asked' => $asked, 'open' => $open ];
	}

	/* ── The merge ─────────────────────────────────────────────────────── */

	/**
	 * A product's gallery after the page's pictures are merged in.
	 *
	 * @param int[]    $current the product's pictures, featured first
	 * @param string[] $urls    the page's pictures, in its order
	 * @param int      $id      the product: a person's choices for it (PictureChoices) apply; 0 = none
	 * @param string   $host    its site, when the caller knows it ('' = its vendor page's)
	 * @return array{
	 *   final: array<int,array{att?:int,url?:string,kind:string,key?:string,choice?:int}>,
	 *   current: array<int,array{att:int,kind:string,of?:string,key?:string,choice?:int}>,
	 *   page: array<int,array{url:string,kind:string,of?:int,key?:string,choice?:int}>,
	 *   counts: array<string,int>, doubt: int, asked: int, changed: bool, featured: int, fps: array<string,array>
	 * }
	 * key: the picture's fingerprint key (how it is recognised on other products); choice: the person's choice that
	 * decided it (a veto on a vetoed one, the featured choice on final[0]); fps: fingerprints by key.
	 */
	public static function merge( array $current, array $urls, bool $ask = false, int $id = 0, string $host = '' ): array {
		self::prefetch( $urls );
		$pics = [];
		foreach ( array_values( $current ) as $att ) {
			$pics[] = [ 'src' => 'c', 'att' => (int) $att, 'fp' => self::of_attachment( (int) $att ), 'file' => static fn() => (string) get_attached_file( (int) $att ) ];
		}
		$nc = count( $pics );
		foreach ( array_values( $urls ) as $u ) {
			$i      = count( $pics );
			$pics[] = [ 'src' => 'v', 'url' => (string) $u, 'fp' => self::of_url( (string) $u ) ];
			$pics[ $i ]['file'] = static function () use ( &$pics, $i ) {
				if ( empty( $pics[ $i ]['tmp'] ) ) {
					$pics[ $i ]['tmp'] = self::download( $pics[ $i ]['url'] );
				}
				return $pics[ $i ]['tmp'];
			};
		}
		$pairs = [];
		$n     = count( $pics );
		for ( $i = 0; $i < $n; $i++ ) {
			for ( $j = $i + 1; $j < $n; $j++ ) {
				$pairs[] = [ $i, $j ];
			}
		}
		$d = self::decide( $pics, $pairs, $ask );
		foreach ( $pics as $p ) {
			if ( ! empty( $p['tmp'] ) && is_file( $p['tmp'] ) ) {
				wp_delete_file( $p['tmp'] );
			}
		}

		// One group per distinct picture.
		$root = range( 0, max( 0, $n - 1 ) );
		$find = static function ( int $x ) use ( &$root ) {
			while ( $root[ $x ] !== $x ) {
				$root[ $x ] = $root[ $root[ $x ] ];
				$x          = $root[ $x ];
			}
			return $x;
		};
		foreach ( array_keys( $d['same'] ) as $k ) {
			[ $i, $j ] = array_map( 'intval', explode( ':', $k ) );
			$ri        = $find( $i );
			$rj        = $find( $j );
			$root[ max( $ri, $rj ) ] = min( $ri, $rj ); // the root is a group's first member
		}
		$groups = [];
		for ( $i = 0; $i < $n; $i++ ) {
			$groups[ $find( $i ) ][] = $i; // keyed by the first member: current pictures first, then the page's order
		}
		ksort( $groups );

		// A person's vetoes: a group is vetoed when any copy in it is (a veto names the picture, not one file of it).
		$rules  = $id ? PictureChoices::for_product( $id, $host ) : [];
		$as_pic = static fn( array $p ) => [ 'att' => (int) ( $p['att'] ?? 0 ), 'url' => (string) ( $p['url'] ?? '' ), 'fp' => $p['fp'] ];
		$veto   = [];
		if ( $rules ) {
			foreach ( $groups as $g => $members ) {
				foreach ( $members as $i ) {
					$r = PictureChoices::match( $rules, $as_pic( $pics[ $i ] ), 'veto' );
					if ( $r ) {
						$veto[ $g ] = $r['id'];
						break;
					}
				}
			}
		}

		$eff  = static fn( array $p ) => $p['fp'] ? min( (int) $p['fp']['eff'], ImageConvert::ZOOM ) : 0; // detail up to what is served (the zoom copy): more is kept as the original, not compared
		$cur  = array_fill( 0, $nc, null );
		$page = array_fill( 0, $n - $nc, null );
		$fin  = [];
		$adds = [];
		foreach ( $groups as $g => $members ) {
			$c = array_values( array_filter( $members, static fn( $i ) => $i < $nc ) );
			$v = array_values( array_filter( $members, static fn( $i ) => $i >= $nc ) );
			usort( $v, static fn( $x, $y ) => $eff( $pics[ $y ] ) <=> $eff( $pics[ $x ] ) ?: $x <=> $y );
			if ( isset( $veto[ $g ] ) ) {
				foreach ( $c as $i ) {
					$cur[ $i ] = [ 'att' => $pics[ $i ]['att'], 'kind' => 'vetoed', 'choice' => $veto[ $g ] ];
				}
				foreach ( $v as $i ) {
					$page[ $i - $nc ] = [ 'url' => $pics[ $i ]['url'], 'kind' => 'vetoed', 'choice' => $veto[ $g ] ];
				}
				continue;
			}
			if ( $c ) {
				$best = $c[0];
				foreach ( $c as $i ) {
					if ( $eff( $pics[ $i ] ) > $eff( $pics[ $best ] ) ) {
						$best = $i;
					}
				}
				$sharp = $v && $pics[ $best ]['fp'] && $eff( $pics[ $v[0] ] ) > $eff( $pics[ $best ] ) * self::SHARPER // owner 2026-11-02: the best copy wins
					&& (string) get_post_meta( (int) $pics[ $best ]['att'], \MMI_Media_Helper::SOURCE_URL_META_KEY, true ) !== (string) $pics[ $v[0] ]['url']; // never the very file it came from
				foreach ( $c as $i ) {
					$cur[ $i ] = [ 'att' => $pics[ $i ]['att'], 'kind' => $sharp ? 'sharper' : ( $i === $best ? ( $pics[ $i ]['fp'] ? 'kept' : 'unreadable' ) : 'duplicate' ) ];
					if ( ! $sharp && $i !== $best ) {
						$cur[ $i ]['of'] = (string) $pics[ $best ]['att'];
					}
				}
				foreach ( $v as $k => $i ) {
					$page[ $i - $nc ] = [ 'url' => $pics[ $i ]['url'], 'kind' => $sharp && $k === 0 ? 'sharper' : 'have', 'of' => $pics[ $best ]['att'] ];
				}
				$fin[] = ( $sharp ? [ 'url' => $pics[ $v[0] ]['url'], 'kind' => 'sharper', 'of' => $pics[ $best ]['att'] ] : [ 'att' => $pics[ $best ]['att'], 'kind' => $cur[ $best ]['kind'] ] ) + [ 'i' => $best ];
			} else {
				foreach ( $v as $k => $i ) {
					$page[ $i - $nc ] = [ 'url' => $pics[ $i ]['url'], 'kind' => $k === 0 ? 'new' : 'have' ];
				}
				$adds[] = $v;
			}
		}
		// A page picture still in doubt against one the product has, or one already being added, isn't added.
		$open     = $d['open'];
		$doubt_of = static function ( array $members, array $others ) use ( $open ): ?int {
			foreach ( $members as $x ) {
				foreach ( $others as $y ) {
					if ( isset( $open[ min( $x, $y ) . ':' . max( $x, $y ) ] ) ) {
						return $y;
					}
				}
			}
			return null;
		};
		$added = [];
		foreach ( $adds as $v ) {
			$i = $v[0];
			$c = $doubt_of( $v, $nc ? range( 0, $nc - 1 ) : [] );
			if ( $c !== null || $doubt_of( $v, $added ) !== null ) {
				$page[ $i - $nc ] = [ 'url' => $pics[ $i ]['url'], 'kind' => 'have', 'doubt' => true ] + ( $c !== null ? [ 'of' => $pics[ $c ]['att'] ] : [] );
				continue;
			}
			$fin[] = [ 'url' => $pics[ $i ]['url'], 'kind' => 'new', 'i' => $i ];
			$added = array_merge( $added, $v );
		}
		// The featured picture a person chose leads: the product's own choice, else its site's.
		$featured = 0;
		if ( $rules ) {
			foreach ( [ 'product', 'site' ] as $scope ) {
				$these = array_values( array_filter( $rules, static fn( $r ) => $r['scope'] === $scope ) );
				foreach ( $fin as $k => $f ) {
					$r = PictureChoices::match( $these, $as_pic( $pics[ $f['i'] ] ), 'featured' );
					if ( $r ) {
						$featured = $r['id'];
						array_unshift( $fin, array_splice( $fin, $k, 1 )[0] );
						break 2;
					}
				}
			}
		}
		// Every picture says which it is, so the same picture is recognised on another product.
		$key = static fn( int $i ) => $pics[ $i ]['fp'] ? [ 'key' => (string) $pics[ $i ]['fp']['key'] ] : [];
		foreach ( $cur as $i => $p ) {
			$cur[ $i ] = $p ? $p + $key( $i ) : $p;
		}
		foreach ( $page as $j => $p ) {
			$page[ $j ] = $p ? $p + $key( $j + $nc ) : $p;
		}
		$fps = [];
		foreach ( $pics as $p ) {
			if ( $p['fp'] ) {
				$fps[ $p['fp']['key'] ] = $p['fp'];
			}
		}
		foreach ( $fin as $k => $f ) {
			$fin[ $k ] = array_diff_key( $f, [ 'i' => 1 ] ) + $key( $f['i'] ) + ( $k === 0 && $featured ? [ 'choice' => $featured ] : [] );
		}
		$moved  = $featured && $current && ( $fin[0]['att'] ?? 0 ) !== (int) reset( $current );
		$counts = array_fill_keys( self::KINDS, 0 );
		foreach ( array_merge( $cur, $page ) as $p ) {
			if ( $p && $p['kind'] !== 'sharper' ) {
				$counts[ $p['kind'] ]++;
			}
		}
		$counts['sharper'] = count( array_filter( $fin, static fn( $f ) => $f['kind'] === 'sharper' ) );
		return [
			'final'   => $fin,
			'current' => array_values( array_filter( $cur ) ),
			'page'    => array_values( array_filter( $page ) ),
			'counts'  => $counts,
			'doubt'   => $d['doubt'],
			'asked'   => $d['asked'],
			'changed' => $counts['new'] + $counts['sharper'] + $counts['duplicate'] > 0 || $moved || array_filter( $cur, static fn( $p ) => $p && $p['kind'] === 'vetoed' ), // a vetoed page picture changes nothing; one the product has comes off
			'featured' => $featured,
			'fps'     => $fps,
		];
	}

	/** "2 new · 1 sharper copy · 1 duplicate removed · 3 kept" */
	public static function summary( array $m ): string {
		$c     = $m['counts'];
		$parts = array_filter(
			[
				$c['new'] ? $c['new'] . ' new' : '',
				$c['sharper'] ? $c['sharper'] . ' sharper ' . _n( 'copy', 'copies', $c['sharper'], 'mmi-data-pipeline' ) : '',
				$c['duplicate'] ? $c['duplicate'] . ' ' . _n( 'duplicate', 'duplicates', $c['duplicate'], 'mmi-data-pipeline' ) . ' removed' : '',
				$c['kept'] + $c['unreadable'] ? ( $c['kept'] + $c['unreadable'] ) . ' kept' : '',
				$c['vetoed'] ? $c['vetoed'] . ' vetoed by a person' : '',
				! empty( $m['featured'] ) ? 'featured chosen by a person' : '',
				$c['over_cap'] ? $c['over_cap'] . ' over the old picture cap' : '',
				$m['doubt'] ? $m['doubt'] . ' in doubt (not added)' : '',
			]
		);
		return implode( ' · ', $parts );
	}
}
