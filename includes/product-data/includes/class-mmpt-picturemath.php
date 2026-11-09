<?php
/**
 * Finds the product inside a padded image and works out the crop that removes
 * the padding while keeping the image's own aspect ratio.
 *
 * Detection runs on a copy scaled down to DETECT_EDGE px (GD only — Imagick
 * isn't installed here). Background = the colour all four corners agree on
 * (or transparency), and it must also cover most of the outer frame, so a
 * photo that merely has four similar corners is left alone.
 *
 * The crop is widened back out to the original ratio around the product's
 * centre and shifted inside the image, so it always fits: the content box is
 * never bigger than the image, and the image already has that ratio.
 *
 * Each measurement also profiles the product itself, at native resolution and
 * flattened on white the way the store shows it:
 *   - phash: a 64-bit difference hash, so copies of the same picture in a
 *     product's gallery can be found whatever their canvas, size or format;
 *   - thumb: a 16×16 colour thumbnail, because the hash is greyscale: colour
 *     variants of one layout (a gold, red and blue skin) share a hash, and only
 *     their hue tells them apart (hue_distance());
 *   - eff: the product's real detail in px on its long side. That's the
 *     content box, reduced when the picture is an upscale: shrinking it to a
 *     factor and growing it back loses (almost) nothing.
 *
 * Part of the Product Data module (moves to mmi-data-pipeline, phase 5a): moved verbatim from
 * Image Trim's Detector (2026-11-09), which now extends this class.
 *
 * @package MannMade\ImageTrim
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PictureMath {

	const DETECT_EDGE      = 400;        // px, longest side of the analysis copy
	const MAX_PIXELS       = 36000000;   // skip anything bigger than ~6000×6000 (GD memory)
	const FRAME_BG_SHARE   = 0.9;        // share of the outer frame that must be background
	const MIN_LINE_PIXELS  = 2;          // a row/column needs this many content px to count (JPEG specks)
	const ALPHA_CLEAR      = 100;        // GD alpha 0–127; above this counts as transparent
	const PROFILE_EDGE     = 1024;       // px, longest side the quality check works at
	const UPSCALE_FACTORS  = [ 0.5, 0.35, 0.25 ];
	const THUMB            = 16;         // px, side of the colour thumbnail stored per image
	const UPSCALE_RATIO    = 0.15;       // detail lost at a factor below this share of edge energy = the image never had it

	/**
	 * Measure one image file.
	 *
	 * @param string $file     Absolute path.
	 * @param array  $settings Store::settings() subset: tolerance, margin_pct, min_gain_pct, min_side.
	 * @return array{status:string,reason?:string,width?:int,height?:int,fill?:float,bg?:string,crop?:array{x:int,y:int,w:int,h:int}}
	 *         status: proposed | tight | skipped.
	 */
	public static function measure( string $file, array $settings ): array {
		$info = is_readable( $file ) ? @getimagesize( $file ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors -- corrupt files are expected, reported as a reason
		if ( ! $info ) {
			return [ 'status' => 'skipped', 'reason' => 'unreadable' ];
		}
		[ $w, $h, $type ] = $info;
		if ( ! in_array( $type, [ IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP ], true ) ) {
			return [ 'status' => 'skipped', 'reason' => 'unsupported_type', 'width' => $w, 'height' => $h ];
		}
		if ( $w * $h > self::MAX_PIXELS ) {
			return [ 'status' => 'skipped', 'reason' => 'too_large', 'width' => $w, 'height' => $h ];
		}

		$src = self::load( $file, $type );
		if ( ! $src ) {
			return [ 'status' => 'skipped', 'reason' => 'unreadable', 'width' => $w, 'height' => $h ];
		}
		$scale = min( 1, self::DETECT_EDGE / max( $w, $h ) );
		$sw    = max( 1, (int) round( $w * $scale ) );
		$sh    = max( 1, (int) round( $h * $scale ) );
		$im    = imagecreatetruecolor( $sw, $sh );
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		imagecopyresampled( $im, $src, 0, 0, 0, 0, $sw, $sh, $w, $h );

		$out = self::analyse( $im, $sw, $sh, (int) $settings['tolerance'] );
		imagedestroy( $im );
		$out += [ 'width' => $w, 'height' => $h ];
		if ( $out['status'] !== 'ok' ) {
			// No plain border: the whole picture is the product.
			$out += self::profile( $src, 0, 0, $w, $h );
			imagedestroy( $src );
			return $out;
		}

		// Content box back at full resolution, grown by one analysis pixel so
		// the downscale's blurred edge never clips the product.
		$step = 1 / $scale;
		$bx0  = max( 0, (int) floor( ( $out['box'][0] - 1 ) * $step ) );
		$by0  = max( 0, (int) floor( ( $out['box'][1] - 1 ) * $step ) );
		$bx1  = min( $w, (int) ceil( ( $out['box'][2] + 2 ) * $step ) );
		$by1  = min( $h, (int) ceil( ( $out['box'][3] + 2 ) * $step ) );
		$cw   = $bx1 - $bx0;
		$ch   = $by1 - $by0;
		$fill = round( max( $cw / $w, $ch / $h ), 3 );

		$crop = self::crop_for( $w, $h, $bx0, $by0, $cw, $ch, $settings );
		$base = [ 'width' => $w, 'height' => $h, 'fill' => $fill, 'bg' => $out['bg'], 'crop' => $crop ] + self::profile( $src, $bx0, $by0, $cw, $ch );
		imagedestroy( $src );

		// "Tight": removing less than min_gain_pct of the width isn't worth a new file.
		if ( $crop['w'] >= $w * ( 1 - $settings['min_gain_pct'] / 100 ) ) {
			return [ 'status' => 'tight' ] + $base;
		}
		return [ 'status' => 'proposed' ] + $base;
	}

	/**
	 * The crop rectangle for a content box: margin, then the image's own ratio,
	 * then at least min_side on the short side (never upscaling).
	 *
	 * @return array{x:int,y:int,w:int,h:int}
	 */
	public static function crop_for( int $w, int $h, int $bx, int $by, int $cw, int $ch, array $settings ): array {
		$ratio  = $w / $h;
		$margin = (int) round( max( $cw, $ch ) * $settings['margin_pct'] / 100 );
		$rw     = $cw + 2 * $margin;
		$rh     = $ch + 2 * $margin;
		if ( $rw / $rh < $ratio ) {
			$rw = $rh * $ratio;
		} else {
			$rh = $rw / $ratio;
		}
		$min_short = min( (int) $settings['min_side'], $w, $h );
		if ( min( $rw, $rh ) < $min_short ) {
			$grow = $min_short / min( $rw, $rh );
			$rw  *= $grow;
			$rh  *= $grow;
		}
		$rw = (int) min( $w, round( $rw ) );
		$rh = (int) min( $h, round( $rh ) );
		$x  = (int) round( $bx + $cw / 2 - $rw / 2 );
		$y  = (int) round( $by + $ch / 2 - $rh / 2 );
		return [
			'x' => max( 0, min( $w - $rw, $x ) ),
			'y' => max( 0, min( $h - $rh, $y ) ),
			'w' => $rw,
			'h' => $rh,
		];
	}

	/**
	 * Background colour and content box on the analysis copy.
	 *
	 * @param \GdImage|resource $im
	 * @return array{status:string,reason?:string,bg?:string,box?:int[]}
	 */
	protected static function analyse( $im, int $sw, int $sh, int $tol ): array {
		$corners = [ [ 0, 0 ], [ $sw - 1, 0 ], [ 0, $sh - 1 ], [ $sw - 1, $sh - 1 ] ];
		$px      = array_map( static fn( $c ) => self::rgba( imagecolorat( $im, $c[0], $c[1] ) ), $corners );

		$clear = count( array_filter( $px, static fn( $p ) => $p[3] > self::ALPHA_CLEAR ) );
		if ( $clear === 4 ) {
			$is_bg = static fn( array $p ) => $p[3] > self::ALPHA_CLEAR;
			$bg    = 'transparent';
		} elseif ( $clear === 0 ) {
			$ref = [
				(int) round( array_sum( array_column( $px, 0 ) ) / 4 ),
				(int) round( array_sum( array_column( $px, 1 ) ) / 4 ),
				(int) round( array_sum( array_column( $px, 2 ) ) / 4 ),
			];
			foreach ( $px as $p ) {
				if ( ! self::near( $p, $ref, $tol ) ) {
					return [ 'status' => 'skipped', 'reason' => 'no_uniform_background' ];
				}
			}
			$is_bg = static fn( array $p ) => $p[3] <= self::ALPHA_CLEAR && self::near( $p, $ref, $tol );
			$bg    = sprintf( '%02x%02x%02x', $ref[0], $ref[1], $ref[2] );
		} else {
			return [ 'status' => 'skipped', 'reason' => 'no_uniform_background' ];
		}

		$rows = array_fill( 0, $sh, 0 );
		$cols = array_fill( 0, $sw, 0 );
		for ( $y = 0; $y < $sh; $y++ ) {
			for ( $x = 0; $x < $sw; $x++ ) {
				if ( ! $is_bg( self::rgba( imagecolorat( $im, $x, $y ) ) ) ) {
					++$rows[ $y ];
					++$cols[ $x ];
				}
			}
		}

		// The outer frame must be (nearly all) background, or this is a photo.
		$frame = $rows[0] + $rows[ $sh - 1 ] + $cols[0] + $cols[ $sw - 1 ];
		if ( $frame > ( 2 * ( $sw + $sh ) ) * ( 1 - self::FRAME_BG_SHARE ) ) {
			return [ 'status' => 'skipped', 'reason' => 'busy_border', 'bg' => $bg ];
		}

		$hit = static fn( $n ) => $n >= self::MIN_LINE_PIXELS;
		$ys  = array_keys( array_filter( $rows, $hit ) );
		$xs  = array_keys( array_filter( $cols, $hit ) );
		if ( ! $ys || ! $xs ) {
			return [ 'status' => 'skipped', 'reason' => 'blank', 'bg' => $bg ];
		}
		return [ 'status' => 'ok', 'bg' => $bg, 'box' => [ min( $xs ), min( $ys ), max( $xs ), max( $ys ) ] ];
	}

	/**
	 * Fingerprint and real detail of the product inside a box of the full image.
	 *
	 * @param \GdImage|resource $src Full image.
	 * @return array{phash:string,content_w:int,content_h:int,eff:int}
	 */
	public static function profile( $src, int $x, int $y, int $cw, int $ch ): array {
		$cw = max( 1, $cw );
		$ch = max( 1, $ch );
		// Flatten on white: a transparent PNG and its white-canvas JPEG copy must hash alike.
		$s  = min( 1, self::PROFILE_EDGE / max( $cw, $ch ) );
		$pw = max( 1, (int) round( $cw * $s ) );
		$ph = max( 1, (int) round( $ch * $s ) );
		$c  = self::canvas( $pw, $ph );
		imagecopyresampled( $c, $src, 0, 0, $x, $y, $pw, $ph, $cw, $ch );

		$small = self::canvas( 9, 8 );
		imagecopyresampled( $small, $c, 0, 0, 0, 0, 9, 8, $pw, $ph );
		$bits = '';
		for ( $row = 0; $row < 8; $row++ ) {
			for ( $col = 0; $col < 8; $col++ ) {
				$bits .= self::luma( $small, $col, $row ) > self::luma( $small, $col + 1, $row ) ? '1' : '0';
			}
		}
		imagedestroy( $small );
		$hash = '';
		foreach ( str_split( $bits, 4 ) as $nibble ) {
			$hash .= dechex( bindec( $nibble ) );
		}

		// A 16×16 colour thumbnail of the product, to confirm that two pictures with
		// close fingerprints really are one picture (the fingerprint is greyscale
		// and coarse: colour variants of one layout share it).
		$t = self::canvas( self::THUMB, self::THUMB );
		imagecopyresampled( $t, $c, 0, 0, 0, 0, self::THUMB, self::THUMB, $pw, $ph );
		$raw = '';
		for ( $row = 0; $row < self::THUMB; $row++ ) {
			for ( $col = 0; $col < self::THUMB; $col++ ) {
				$v    = imagecolorat( $t, $col, $row );
				$raw .= chr( ( $v >> 16 ) & 255 ) . chr( ( $v >> 8 ) & 255 ) . chr( $v & 255 );
			}
		}
		imagedestroy( $t );

		$factor = self::upscale_factor( $c, $pw, $ph );
		imagedestroy( $c );
		return [
			'thumb'     => base64_encode( $raw ),
			'phash'     => $hash,
			'content_w' => $cw,
			'content_h' => $ch,
			'eff'       => (int) round( max( $cw, $ch ) * $factor ),
		];
	}

	/**
	 * Largest hue difference between two colour thumbnails, over a 4×4 grid of
	 * block averages: |Δ(R−G)| + |Δ(G−B)| of the worst block. Brightness is left
	 * out on purpose, and block averages absorb the small shift between two crops
	 * of one picture (a transparent original vs its white-canvas copy). Measured
	 * 2026-10-01: real copies 1.7–4.3, gold/red/blue variants of one layout 116–168.
	 * 999 when either thumbnail is missing.
	 */
	public static function hue_distance( string $a, string $b ): float {
		$a = base64_decode( $a, true );
		$b = base64_decode( $b, true );
		$n = self::THUMB * self::THUMB * 3;
		if ( ! $a || ! $b || strlen( $a ) !== $n || strlen( $b ) !== $n ) {
			return 999.0;
		}
		$blocks = self::THUMB / 4;
		$avg    = static function ( string $raw ) use ( $blocks ) {
			$out = array_fill( 0, 16, [ 0, 0, 0 ] );
			for ( $y = 0; $y < self::THUMB; $y++ ) {
				for ( $x = 0; $x < self::THUMB; $x++ ) {
					$i = ( $y * self::THUMB + $x ) * 3;
					$k = intdiv( $y, $blocks ) * 4 + intdiv( $x, $blocks );
					for ( $c = 0; $c < 3; $c++ ) {
						$out[ $k ][ $c ] += ord( $raw[ $i + $c ] ) / ( $blocks * $blocks );
					}
				}
			}
			return $out;
		};
		$pa  = $avg( $a );
		$pb  = $avg( $b );
		$max = 0.0;
		for ( $k = 0; $k < 16; $k++ ) {
			$d   = abs( ( $pa[ $k ][0] - $pa[ $k ][1] ) - ( $pb[ $k ][0] - $pb[ $k ][1] ) ) + abs( ( $pa[ $k ][1] - $pa[ $k ][2] ) - ( $pb[ $k ][1] - $pb[ $k ][2] ) );
			$max = max( $max, $d );
		}
		return round( $max, 1 );
	}

	/** Hamming distance between two hex phashes (64 = unrelated). */
	public static function distance( string $a, string $b ): int {
		if ( strlen( $a ) !== 16 || strlen( $b ) !== 16 ) {
			return 64;
		}
		$d = 0;
		for ( $i = 0; $i < 16; $i++ ) {
			$d += substr_count( decbin( hexdec( $a[ $i ] ) ^ hexdec( $b[ $i ] ) ), '1' );
		}
		return $d;
	}

	/**
	 * Smallest factor the picture can be shrunk to and grown back from without
	 * losing detail (1 = it uses all its pixels). Detail lost is measured against
	 * the picture's own edge energy, so flat graphics and busy photos compare alike.
	 *
	 * @param \GdImage|resource $c
	 */
	protected static function upscale_factor( $c, int $w, int $h ): float {
		$step  = max( 1, (int) floor( sqrt( $w * $h / 10000 ) ) ); // ≈ 10k samples: plenty for an average, 4× cheaper than every pixel of a 1024 px crop
		$edges = 0;
		$n     = 0;
		for ( $y = 0; $y < $h - 1; $y += $step ) {
			for ( $x = 0; $x < $w - 1; $x += $step ) {
				$l      = self::luma( $c, $x, $y );
				$edges += abs( $l - self::luma( $c, $x + 1, $y ) ) + abs( $l - self::luma( $c, $x, $y + 1 ) );
				++$n;
			}
		}
		$edges /= max( 1, $n );
		if ( $edges < 1 ) {
			return 1.0; // flat: nothing to judge
		}
		$best = 1.0;
		foreach ( self::UPSCALE_FACTORS as $f ) {
			$dw   = max( 1, (int) round( $w * $f ) );
			$dh   = max( 1, (int) round( $h * $f ) );
			$down = self::canvas( $dw, $dh );
			imagecopyresampled( $down, $c, 0, 0, 0, 0, $dw, $dh, $w, $h );
			$up = self::canvas( $w, $h );
			imagecopyresampled( $up, $down, 0, 0, 0, 0, $w, $h, $dw, $dh );
			imagedestroy( $down );
			$lost = 0;
			$m    = 0;
			for ( $y = 0; $y < $h; $y += $step ) {
				for ( $x = 0; $x < $w; $x += $step ) {
					$lost += abs( self::luma( $c, $x, $y ) - self::luma( $up, $x, $y ) );
					++$m;
				}
			}
			imagedestroy( $up );
			if ( ( $lost / max( 1, $m ) ) / $edges >= self::UPSCALE_RATIO ) {
				break;
			}
			$best = $f;
		}
		return $best;
	}

	/** @return \GdImage|resource White truecolor canvas. */
	protected static function canvas( int $w, int $h ) {
		$im = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 255, 255, 255 ) );
		return $im;
	}

	protected static function luma( $im, int $x, int $y ): float {
		$c = imagecolorat( $im, $x, $y );
		return 0.299 * ( ( $c >> 16 ) & 255 ) + 0.587 * ( ( $c >> 8 ) & 255 ) + 0.114 * ( $c & 255 );
	}

	/** @return \GdImage|resource|null */
	protected static function load( string $file, int $type ) {
		$im = match ( $type ) {
			IMAGETYPE_JPEG => @imagecreatefromjpeg( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors -- libjpeg warns on recoverable corruption
			IMAGETYPE_PNG  => @imagecreatefrompng( $file ), // phpcs:ignore WordPress.PHP.NoSilencedErrors
			IMAGETYPE_WEBP => function_exists( 'imagecreatefromwebp' ) ? @imagecreatefromwebp( $file ) : false, // phpcs:ignore WordPress.PHP.NoSilencedErrors
			default        => false,
		};
		return $im ?: null;
	}

	/** @return int[] r, g, b, alpha (0 opaque – 127 clear) */
	protected static function rgba( int $c ): array {
		return [ ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255, ( $c >> 24 ) & 127 ];
	}

	protected static function near( array $p, array $ref, int $tol ): bool {
		return abs( $p[0] - $ref[0] ) <= $tol && abs( $p[1] - $ref[1] ) <= $tol && abs( $p[2] - $ref[2] ) <= $tol;
	}
}
