<?php
/**
 * Every product image this module imports becomes one standard file (owner
 * 2026-10-08): a JPEG of exactly 800×800 px, the picture scaled to fit and
 * centred on white, aiming for under 100 KB ("PNGs are too large").
 *
 * GD does the work. It reads JPEG, PNG, WebP and GIF here but not AVIF (GD is
 * built without it and Imagick isn't installed), so an AVIF — or HEIC — is
 * first decoded by the server's headless Chromium, the one
 * PageStore::render() uses (free, nothing installed), into a lossless PNG
 * (scripts/product-content/convert-image.js). Chromium starts from WP-CLI
 * only, with the same load/RAM guard as page rendering; a web request leaves
 * such an image to the next CLI run (Enrichment::apply_waiting(), every 30
 * minutes via `wp mmpt pick`).
 *
 * Generated sizes (owner D3, 2026-10-08, PRODUCT_IMAGE_REPLACEMENT_HANDOFF.md
 * §4.8): a standard picture gets only the 300 and 100 px copies. WordPress's
 * 768 copy weighed nearly as much as the 800 original it came from (57 KB
 * against 65 KB), so with 768 and 600 a picture cost 180 KB; without them
 * 82 KB. Pages asking for `large`, `medium_large` or `woocommerce_single`
 * get the 800 original, scaled by the browser; other uploads keep every size.
 *
 * standardise() re-saves a product's existing picture to the same standard
 * (owner D7: products whose vendor page has no usable pictures keep their
 * own pictures, in the standard file).
 *
 * The best copy, kept and served well (owner 2026-11-02: "strive to obtain the best quality (sharpest, largest file
 * size) images we can from wherever we can and serve the best web-friendly versions of them"; the owner chose: the
 * 800 standard stays the main picture, plus a 1600 copy for zoom and sharp screens, WebP where it is smaller):
 * - the original as downloaded (or the file a re-save started from) is kept in a private folder the site never serves
 *   (MASTER_DIR, attachment meta MASTER_META: file, width, height, bytes, type, source), whatever its format, a TIFF
 *   included; deleting the attachment deletes it;
 * - a picture whose original is larger than ZOOM_FROM px gets a ZOOM px copy beside the standard file, JPEG or WebP,
 *   whichever is smaller, as the attachment's ZOOM_SIZE size: WordPress puts it in the srcset (sharp screens), and the
 *   product gallery's lightbox asks for it instead of "full" (woocommerce_gallery_full_size);
 * - PictureMatch ranks copies by detail up to ZOOM px, so a larger maker copy replaces a picture that only ever had the
 *   800 file (Sharper copy).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImageConvert {

	const SIZE        = 800;
	const MAX_BYTES   = 100 * 1024;
	const QUALITIES   = [ 86, 80, 74, 68, 62, 56, 50 ]; // the first that fits MAX_BYTES; the last one otherwise
	const SCRIPT      = 'scripts/product-content/convert-image.js';
	const ZOOM        = 1600;          // the zoom / sharp-screen copy
	const ZOOM_FROM   = 920;           // SIZE × PictureMatch::SHARPER: an original that could win a Sharper copy gets a zoom copy, so the copy that won measures as much next time (no swap loop)
	const ZOOM_BYTES  = 350 * 1024;
	const ZOOM_SIZE   = 'mmpt_zoom';   // its name in the attachment's sizes
	const MASTER_DIR  = 'picture-masters';
	const MASTER_META = '_mmpt_master';
	/** File extensions for originals by type (anything else keeps its own). */
	const MASTER_EXT  = [ 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif', 'image/avif' => 'avif', 'image/heic' => 'heic', 'image/tiff' => 'tif', 'image/bmp' => 'bmp' ];
	const TIMEOUT_S   = 60;

	/** The only generated sizes a standard picture gets (the 100 px thumbnail and gallery thumbnail share one file). */
	const KEEP_SIZES = [ 'thumbnail', 'woocommerce_thumbnail', 'woocommerce_gallery_thumbnail' ];

	/** True while add_file() sideloads a standard picture, before its meta exists. */
	private static bool $importing = false;

	public static function register_hooks(): void {
		add_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'standard_sizes' ], 20, 3 );
		add_filter( 'wp_get_missing_image_subsizes', [ __CLASS__, 'standard_sizes' ], 20, 3 ); // a regenerate doesn't add them back
		add_filter( 'woocommerce_gallery_full_size', [ __CLASS__, 'zoom_size' ], 20 );
		add_action( 'delete_attachment', [ __CLASS__, 'forget_master' ] );
	}

	/** The product gallery's lightbox asks for "full": the zoom copy where a picture has one (image_downsize() falls back to full). */
	public static function zoom_size( $size ) {
		return $size === 'full' ? self::ZOOM_SIZE : $size;
	}

	/** Sizes to make: only KEEP_SIZES for a standard picture, everything for anything else. */
	public static function standard_sizes( $sizes, $meta = [], $att = 0 ) {
		if ( ! is_array( $sizes ) || ! ( self::$importing || ( $att && self::is_standard( (int) $att ) ) ) ) {
			return $sizes;
		}
		return array_intersect_key( $sizes, array_flip( self::KEEP_SIZES ) );
	}

	/** An attachment this module made to the standard (imported or re-saved). */
	public static function is_standard( int $att ): bool {
		return (bool) get_post_meta( $att, '_mmpt_source_size', true );
	}

	/** Formats GD reads here → its reader. */
	const GD_READERS = [ 'image/jpeg' => 'imagecreatefromjpeg', 'image/png' => 'imagecreatefrompng', 'image/webp' => 'imagecreatefromwebp', 'image/gif' => 'imagecreatefromgif' ];

	/** Formats only Chromium decodes here, with the file extensions that usually carry them. */
	const BROWSER_ONLY = [ 'image/avif' => [ 'avif' ], 'image/heic' => [ 'heic', 'heif' ] ];

	/** Whether GD can't read this type, so Chromium has to decode it first. */
	public static function needs_browser( string $mime ): bool {
		return isset( self::BROWSER_ONLY[ $mime ] ) && ! function_exists( self::GD_READERS[ $mime ] ?? 'imagecreatefromavif' );
	}

	/** Whether a URL's file name says it needs Chromium (a guess before downloading). */
	public static function url_needs_browser( string $url ): bool {
		$ext = strtolower( (string) pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		foreach ( self::BROWSER_ONLY as $mime => $exts ) {
			// audit-ok: haystack is a 1-2 item extension list from the BROWSER_ONLY constant
			if ( in_array( $ext, $exts, true ) && self::needs_browser( $mime ) ) {
				return true;
			}
		}
		return false;
	}

	/** Chromium can run now: WP-CLI, and the server isn't over its load/RAM limits. */
	public static function browser_available(): bool {
		return defined( 'WP_CLI' ) && WP_CLI && ! PageStore::server_busy();
	}

	/**
	 * Decode a browser-only image into a new PNG temp file (caller deletes both).
	 *
	 * @return string|\WP_Error
	 */
	public static function decode( string $file, string $mime ) {
		if ( ! self::browser_available() ) {
			return new \WP_Error( 'mmpt_convert_later', 'Image conversion runs from WP-CLI when the server is not busy.' );
		}
		$node   = is_executable( '/usr/bin/node' ) ? '/usr/bin/node' : '/usr/local/bin/node';
		$home   = function_exists( 'posix_getpwuid' ) ? (string) ( posix_getpwuid( posix_geteuid() )['dir'] ?? '' ) : (string) getenv( 'HOME' );
		$chrome = glob( $home . '/.cache/ms-playwright/chromium-*/chrome-linux64/chrome' );
		$script = dirname( __DIR__, 3 ) . '/' . self::SCRIPT;
		$out    = wp_tempnam( 'mmpt-decode.png' );
		if ( ! is_executable( $node ) || ! $chrome || ! is_readable( $script ) || $out === '' ) {
			return new \WP_Error( 'mmpt_convert_setup', 'Image converter is not available (node, Chromium or script missing).' );
		}
		$proc = proc_open(
			[ 'timeout', (string) self::TIMEOUT_S, $node, $script, $file, $out ],
			[ 1 => [ 'pipe', 'w' ], 2 => [ 'pipe', 'w' ] ],
			$pipes,
			null,
			[ 'NODE_PATH' => $home . '/.cache/mmi-browser-checks/node_modules', 'MMI_RENDER_CHROME' => (string) end( $chrome ), 'MMI_CONVERT_MIME' => $mime, 'HOME' => $home, 'PATH' => '/usr/local/bin:/usr/bin:/bin' ]
		);
		if ( ! is_resource( $proc ) ) {
			wp_delete_file( $out );
			return new \WP_Error( 'mmpt_convert_start', 'Image converter could not start.' );
		}
		$stdout = (string) stream_get_contents( $pipes[1] );
		stream_get_contents( $pipes[2] );
		fclose( $pipes[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $pipes[2] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		proc_close( $proc );
		$lines = array_filter( explode( "\n", trim( $stdout ) ) );
		$res   = (array) json_decode( (string) end( $lines ), true );
		if ( ( $res['status'] ?? '' ) !== 'ok' || ! is_readable( $out ) || wp_get_image_mime( $out ) !== 'image/png' ) {
			wp_delete_file( $out );
			return new \WP_Error( 'mmpt_convert_failed', 'Image decoding failed: ' . (string) ( $res['status'] ?? 'no result' ) );
		}
		return $out;
	}

	/**
	 * The standard product image: SIZE×SIZE JPEG, the picture scaled to fit
	 * (up or down) and centred on white, transparency flattened onto white,
	 * quality lowered step by step until it fits MAX_BYTES. Returns a new temp
	 * file (caller deletes both) and what was done.
	 *
	 * @return array{file:string,width:int,height:int,bytes:int,quality:int}|\WP_Error
	 */
	public static function normalize( string $file, int $size = self::SIZE, int $max = self::MAX_BYTES, bool $webp = false ) {
		$mime   = (string) wp_get_image_mime( $file );
		$reader = self::GD_READERS[ $mime ] ?? '';
		if ( $reader === '' || ! function_exists( $reader ) ) {
			return new \WP_Error( 'mmpt_image_type', 'Not an image GD can read: ' . ( $mime ?: 'unknown type' ) );
		}
		$src = @$reader( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a corrupt download is reported below
		if ( ! $src ) {
			return new \WP_Error( 'mmpt_image_read', 'The image could not be read.' );
		}
		$w     = imagesx( $src );
		$h     = imagesy( $src );
		$scale = min( $size / $w, $size / $h );
		$nw    = max( 1, (int) round( $w * $scale ) );
		$nh    = max( 1, (int) round( $h * $scale ) );
		$dst   = imagecreatetruecolor( $size, $size );
		imagefill( $dst, 0, 0, imagecolorallocate( $dst, 255, 255, 255 ) );
		imagealphablending( $dst, true ); // transparent pixels blend onto the white
		imagecopyresampled( $dst, $src, (int) floor( ( $size - $nw ) / 2 ), (int) floor( ( $size - $nh ) / 2 ), 0, 0, $nw, $nh, $w, $h );
		imagedestroy( $src );
		imageinterlace( $dst, true ); // progressive
		$out  = wp_tempnam( 'mmpt-image.jpg' );
		$q    = 0;
		$type = 'image/jpeg';
		foreach ( self::QUALITIES as $q ) {
			imagejpeg( $dst, $out, $q );
			clearstatcache( true, $out );
			if ( filesize( $out ) <= $max ) {
				break;
			}
		}
		if ( $webp && function_exists( 'imagewebp' ) ) { // the same quality as WebP, kept when it is smaller
			$alt = wp_tempnam( 'mmpt-image.webp' );
			$made = imagewebp( $dst, $alt, $q );
			clearstatcache( true, $alt );
			if ( $made && filesize( $alt ) > 0 && filesize( $alt ) < filesize( $out ) ) {
				wp_delete_file( $out );
				$out  = $alt;
				$type = 'image/webp';
			} else {
				wp_delete_file( $alt );
			}
		}
		imagedestroy( $dst );
		return [ 'file' => $out, 'width' => $w, 'height' => $h, 'bytes' => (int) filesize( $out ), 'quality' => (int) $q, 'type' => $type ];
	}

	/**
	 * Keep a picture's original (as downloaded, or the file a re-save started from) for attachment $att, in the private
	 * MASTER_DIR, whatever its format. Its size is read where PHP can (getimagesize() reads TIFF too).
	 */
	private static function keep_master( string $file, int $att, string $source ): void {
		$dir = function_exists( 'mmi_shared_lib_private_subdir' ) ? mmi_shared_lib_private_subdir( self::MASTER_DIR ) : '';
		if ( $dir === '' || ! is_readable( $file ) ) {
			return;
		}
		$mime = (string) ( wp_get_image_mime( $file ) ?: ( @getimagesize( $file )['mime'] ?? '' ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- an unreadable file has no size
		$ext  = self::MASTER_EXT[ $mime ] ?? ( strtolower( (string) pathinfo( (string) wp_parse_url( $source, PHP_URL_PATH ), PATHINFO_EXTENSION ) ) ?: 'bin' );
		$name = $att . '-' . substr( md5( $source !== '' ? $source : $file ), 0, 12 ) . '.' . preg_replace( '/[^a-z0-9]/', '', $ext );
		if ( ! @copy( $file, $dir . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a full disk leaves the picture without its original, nothing worse
			return;
		}
		$dim = @getimagesize( $dir . $name ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		update_post_meta( $att, self::MASTER_META, [ 'file' => $name, 'width' => (int) ( $dim[0] ?? 0 ), 'height' => (int) ( $dim[1] ?? 0 ), 'bytes' => (int) filesize( $dir . $name ), 'type' => $mime, 'source' => mb_substr( $source, 0, 500 ), 'at' => current_time( 'mysql', true ) ] );
	}

	/** The picture's zoom copy's path, or '' when it has none. */
	public static function zoom_path( int $att ): string {
		$meta = wp_get_attachment_metadata( $att );
		$file = is_array( $meta ) ? (string) ( $meta['sizes'][ self::ZOOM_SIZE ]['file'] ?? '' ) : '';
		$main = $file !== '' ? (string) get_attached_file( $att ) : '';
		return $main !== '' && is_readable( dirname( $main ) . '/' . $file ) ? dirname( $main ) . '/' . $file : '';
	}

	/** The kept original's path, or '' when the picture has none. */
	public static function master_path( int $att ): string {
		$m   = get_post_meta( $att, self::MASTER_META, true );
		$dir = is_array( $m ) && ! empty( $m['file'] ) && function_exists( 'mmi_shared_lib_private_subdir' ) ? mmi_shared_lib_private_subdir( self::MASTER_DIR ) : '';
		return $dir !== '' && is_readable( $dir . $m['file'] ) ? $dir . $m['file'] : '';
	}

	/** delete_attachment: the picture's original goes with it. */
	public static function forget_master( $att ): void {
		$path = self::master_path( (int) $att );
		if ( $path !== '' ) {
			wp_delete_file( $path );
		}
	}

	/**
	 * Add the zoom copy (ZOOM px, made from $file, a GD-readable original) to attachment $att's sizes, beside its
	 * standard file. Only when the original is larger than ZOOM_FROM: a smaller one would only be upscaled.
	 */
	private static function add_zoom( int $att, string $file ): void {
		$dim = @getimagesize( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $dim || max( (int) $dim[0], (int) $dim[1] ) < self::ZOOM_FROM ) {
			return;
		}
		$side = min( self::ZOOM, max( (int) $dim[0], (int) $dim[1] ) );
		$z    = self::normalize( $file, $side, self::ZOOM_BYTES, true );
		$main = get_attached_file( $att );
		$meta = wp_get_attachment_metadata( $att );
		if ( is_wp_error( $z ) || ! $main || ! is_array( $meta ) ) {
			if ( ! is_wp_error( $z ) ) {
				wp_delete_file( $z['file'] );
			}
			return;
		}
		$name = pathinfo( $main, PATHINFO_FILENAME ) . '-zoom.' . ( $z['type'] === 'image/webp' ? 'webp' : 'jpg' );
		if ( ! @rename( $z['file'], dirname( $main ) . '/' . $name ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_delete_file( $z['file'] );
			return;
		}
		$meta['sizes']                    = (array) ( $meta['sizes'] ?? [] );
		$meta['sizes'][ self::ZOOM_SIZE ] = [ 'file' => $name, 'width' => $side, 'height' => $side, 'mime-type' => $z['type'], 'filesize' => (int) $z['bytes'] ];
		wp_update_attachment_metadata( $att, $meta );
	}

	/**
	 * Download an image and add it to the media library for $post_id as the
	 * standard JPEG, tagged with its source URL (MMI_Media_Helper's dedup key).
	 * 'wait' when it needs Chromium and Chromium can't run now.
	 *
	 * @return int|string|\WP_Error attachment ID, 'wait', or the error
	 */
	public static function import( string $url, int $post_id ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		if ( self::url_needs_browser( $url ) && ! self::browser_available() ) {
			return 'wait';
		}
		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		$mime = (string) wp_get_image_mime( $tmp );
		$file = $tmp;
		if ( self::needs_browser( $mime ) ) {
			if ( ! self::browser_available() ) {
				wp_delete_file( $tmp );
				return 'wait';
			}
			$file = self::decode( $tmp, $mime );
			if ( is_wp_error( $file ) ) {
				wp_delete_file( $tmp );
				return $file;
			}
		}
		$std = self::normalize( $file );
		if ( is_wp_error( $std ) ) {
			wp_delete_file( $file );
			wp_delete_file( $tmp );
			return $std;
		}
		$att = self::add_file( $std, $post_id, (string) wp_parse_url( $url, PHP_URL_PATH ), [ 'from' => $mime ] );
		if ( ! is_wp_error( $att ) ) {
			\MMI_Media_Helper::tag_source_url( $att, $url );
			self::keep_master( $tmp, $att, $url ); // the original as downloaded
			self::add_zoom( $att, $file );
		}
		wp_delete_file( $file );
		if ( $file !== $tmp ) {
			wp_delete_file( $tmp );
		}
		return $att;
	}

	/**
	 * Re-save one of a product's own pictures as the standard file (D7): the
	 * same picture, a new attachment for $post_id. The live file is used, so an
	 * Image Trim crop stays. A picture already re-saved (for a product sharing
	 * it) is reused, so a family keeps sharing one file.
	 *
	 * @return int|string|\WP_Error the standard attachment's ID, 'standard' (it already is one),
	 *                              'wait' (needs Chromium, which can't run now), or the error
	 */
	public static function standardise( int $att, int $post_id ) {
		global $wpdb;
		if ( self::is_standard( $att ) ) {
			return 'standard';
		}
		$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT pm.post_id FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'attachment' WHERE pm.meta_key = '_mmpt_standardised_from' AND pm.meta_value = %s LIMIT 1", (string) $att ) );
		if ( $done ) {
			return $done;
		}
		$file = get_attached_file( $att );
		if ( ! $file || ! is_readable( $file ) ) {
			return new \WP_Error( 'mmpt_image_missing', 'The picture\'s file is missing.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$mime = (string) wp_get_image_mime( $file );
		$src  = $file;
		if ( self::needs_browser( $mime ) ) {
			if ( ! self::browser_available() ) {
				return 'wait';
			}
			$src = self::decode( $file, $mime );
			if ( is_wp_error( $src ) ) {
				return $src;
			}
		}
		$std = self::normalize( $src );
		if ( is_wp_error( $std ) ) {
			if ( $src !== $file ) {
				wp_delete_file( $src );
			}
			return $std;
		}
		$new = self::add_file( $std, $post_id, $file, [ 'from' => $mime, 'standardised_from' => $att ] );
		if ( ! is_wp_error( $new ) ) {
			$old = self::master_path( $att );
			self::keep_master( $old !== '' ? $old : $file, $new, (string) get_post_meta( $att, \MMI_Media_Helper::SOURCE_URL_META_KEY, true ) ); // the file it started from: nothing better is known
			self::add_zoom( $new, $src );
		}
		if ( $src !== $file ) {
			wp_delete_file( $src );
		}
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		update_post_meta( $new, '_mmpt_standardised_from', (string) $att );
		$alt = (string) get_post_meta( $att, '_wp_attachment_image_alt', true );
		if ( $alt !== '' ) {
			update_post_meta( $new, '_wp_attachment_image_alt', $alt );
		}
		$source = (string) get_post_meta( $att, \MMI_Media_Helper::SOURCE_URL_META_KEY, true );
		if ( $source !== '' ) {
			\MMI_Media_Helper::tag_source_url( $new, $source ); // a later import of the same source finds the standard file
		}
		return $new;
	}

	/**
	 * Add a normalize()d temp file to the media library for $post_id as a
	 * standard picture (only KEEP_SIZES are generated). $name_from is a path
	 * or URL path whose file name names the new file. Deletes the temp file.
	 *
	 * @return int|\WP_Error
	 */
	private static function add_file( array $std, int $post_id, string $name_from, array $source ) {
		$name            = sanitize_file_name( pathinfo( basename( $name_from ), PATHINFO_FILENAME ) ?: 'image' ) . '.jpg';
		self::$importing = true;
		try {
			$att = media_handle_sideload( [ 'name' => $name, 'tmp_name' => $std['file'] ], $post_id );
		} finally {
			self::$importing = false;
		}
		if ( is_wp_error( $att ) ) {
			wp_delete_file( $std['file'] );
			return $att;
		}
		update_post_meta( (int) $att, '_mmpt_source_size', [ 'width' => $std['width'], 'height' => $std['height'], 'bytes' => $std['bytes'], 'quality' => $std['quality'] ] + $source );
		return (int) $att;
	}
}
