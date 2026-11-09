<?php
/**
 * Removing the media the new product pictures replace (owner 2026-10-08,
 * PRODUCT_IMAGE_REPLACEMENT_HANDOFF.md §4.4), in three steps that each wait:
 *
 *   released    — no longer one of the product's pictures. Nothing moves for
 *                 RELEASE_AFTER (pages cached at the edge for an hour, the
 *                 Google feed and a person's revert all still see the files).
 *   quarantined — after a reference check finds nothing else using it, every
 *                 file moves to ~/quarantine/media-replaced-<date>/ with a
 *                 manifest, the attachment's post row and meta are kept there
 *                 as JSON, and the attachment is deleted. restore() undoes it.
 *   deleted     — only after GRACE_DAYS (owner D4: 14 days) and the owner's
 *                 go-ahead for that folder (`wp mmpt images purge <folder> --yes`).
 *
 * An attachment something else still uses (another product, a page, a logo,
 * an option, an open picker row) is `kept`, with the reason. Kind `sizes` is a
 * standard picture's dropped 768/600 copies (owner D3): only those files move.
 *
 * File moves run from WP-CLI only: PHP-FPM's open_basedir can't reach ~/quarantine.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MediaRelease {

	const DB_OPTION     = 'mmpt_media_release_db_version';
	const DB_VERSION    = '1';
	const RELEASE_AFTER = 2 * DAY_IN_SECONDS;
	const GRACE_DAYS    = 14;
	const DIR_PREFIX    = 'media-replaced-';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_media_release';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta( "CREATE TABLE {$t} (
  attachment_id bigint(20) unsigned NOT NULL,
  kind varchar(12) NOT NULL DEFAULT 'attachment',
  state varchar(12) NOT NULL DEFAULT 'released',
  reason varchar(120) NOT NULL DEFAULT '',
  bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  files longtext,
  products text,
  released_at datetime DEFAULT NULL,
  release_after datetime DEFAULT NULL,
  quarantined_at datetime DEFAULT NULL,
  quarantine_dir varchar(255) NOT NULL DEFAULT '',
  deleted_at datetime DEFAULT NULL,
  PRIMARY KEY  (attachment_id, kind),
  KEY due (state, release_after),
  KEY qdir (quarantine_dir)
) {$wpdb->get_charset_collate()};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	public static function base_dir(): string {
		return (string) apply_filters( 'mmpt_media_quarantine_base', trailingslashit( dirname( untrailingslashit( ABSPATH ), 2 ) ) . 'quarantine' );
	}

	/* ── Step 1: release ───────────────────────────────────────────────── */

	/**
	 * Mark an attachment (or, kind `sizes`, some of its files) as no longer
	 * needed. A row already quarantined or deleted is left alone.
	 *
	 * @param array $files kind `sizes`: [ 'rel' => [...], 'sizes' => [ name => metadata entry ] ]
	 */
	public static function release( int $att, array $products, string $reason, string $kind = 'attachment', array $files = [] ): void {
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$now = time();
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$t} (attachment_id, kind, state, reason, bytes, files, products, released_at, release_after)
				 VALUES (%d, %s, 'released', %s, %d, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE
				   reason = IF( state IN ('quarantined','deleted'), reason, VALUES(reason) ),
				   files = IF( state IN ('quarantined','deleted'), files, VALUES(files) ),
				   bytes = IF( state IN ('quarantined','deleted'), bytes, VALUES(bytes) ),
				   products = IF( state IN ('quarantined','deleted'), products, VALUES(products) ),
				   released_at = IF( state IN ('quarantined','deleted'), released_at, VALUES(released_at) ),
				   release_after = IF( state IN ('quarantined','deleted'), release_after, VALUES(release_after) ),
				   state = IF( state IN ('quarantined','deleted'), state, 'released' )",
				$att,
				$kind,
				mb_substr( $reason, 0, 120 ),
				$kind === 'sizes' ? self::sum_bytes( (array) ( $files['rel'] ?? [] ) ) : self::sum_bytes( self::files_of( $att ) ),
				$files ? wp_json_encode( $files ) : null,
				wp_json_encode( array_values( array_unique( array_map( 'intval', $products ) ) ) ),
				gmdate( 'Y-m-d H:i:s', $now ),
				gmdate( 'Y-m-d H:i:s', $now + self::RELEASE_AFTER )
			)
		);
	}

	/** A released attachment is wanted again (a revert put it back): nothing will move. */
	public static function unrelease( int $att, string $why ): void {
		self::maybe_install();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET state = 'kept', reason = %s WHERE attachment_id = %d AND kind = 'attachment' AND state = 'released'", mb_substr( $why, 0, 120 ), $att ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Every file of an attachment, relative to uploads: original, generated sizes, original_image, Image Trim backups. @return string[] */
	public static function files_of( int $att ): array {
		$rel = (string) get_post_meta( $att, '_wp_attached_file', true );
		if ( $rel === '' ) {
			return [];
		}
		$dir  = dirname( $rel ) === '.' ? '' : trailingslashit( dirname( $rel ) );
		$out  = [ $rel ];
		$meta = (array) wp_get_attachment_metadata( $att, true );
		foreach ( (array) ( $meta['sizes'] ?? [] ) as $s ) {
			if ( ! empty( $s['file'] ) ) {
				$out[] = $dir . $s['file'];
			}
		}
		if ( ! empty( $meta['original_image'] ) ) {
			$out[] = $dir . $meta['original_image'];
		}
		foreach ( (array) get_post_meta( $att, '_wp_attachment_backup_sizes', true ) as $b ) {
			if ( is_array( $b ) && ! empty( $b['file'] ) ) {
				$out[] = $dir . $b['file'];
			}
		}
		$up = trailingslashit( wp_get_upload_dir()['basedir'] );
		return array_values( array_filter( array_unique( $out ), static fn( $f ) => is_file( $up . $f ) ) );
	}

	public static function sum_bytes( array $rel ): int {
		$up = trailingslashit( wp_get_upload_dir()['basedir'] );
		$n  = 0;
		foreach ( $rel as $f ) {
			$n += is_file( $up . $f ) ? (int) filesize( $up . $f ) : 0;
		}
		return $n;
	}

	/* ── The reference check ───────────────────────────────────────────── */

	/**
	 * What still uses each attachment, besides the products it was released
	 * from (which no longer list it). Empty = nothing; safe to quarantine.
	 *
	 * @param int[] $atts
	 * @return array<int,string[]>
	 */
	public static function references( array $atts ): array {
		global $wpdb;
		$atts = array_values( array_unique( array_filter( array_map( 'intval', $atts ) ) ) );
		$out  = array_fill_keys( $atts, [] );
		if ( ! $atts ) {
			return $out;
		}
		$in = implode( ',', $atts );
		// Featured picture of any post, published or not.
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value IN ('" . implode( "','", $atts ) . "')", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints
			$out[ (int) $r['meta_value'] ][] = 'featured picture of #' . $r['post_id'];
		}
		// Gallery of any post.
		$want = array_flip( $atts );
		foreach ( $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_product_image_gallery' AND meta_value <> ''", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- no input
			foreach ( explode( ',', (string) $r['meta_value'] ) as $g ) {
				if ( isset( $want[ (int) $g ] ) ) {
					$out[ (int) $g ][] = 'gallery of #' . $r['post_id'];
				}
			}
		}
		// Term meta holding the ID (brand logos, category pictures).
		foreach ( $wpdb->get_results( "SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE meta_value IN ('" . implode( "','", $atts ) . "')", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints
			$out[ (int) $r['meta_value'] ][] = "term #{$r['term_id']} ({$r['meta_key']})";
		}
		// Options holding the ID (site icon, placeholder) and the theme's logo.
		foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_value IN ('" . implode( "','", $atts ) . "')", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints
			$out[ (int) $r['option_value'] ][] = 'option ' . $r['option_name'];
		}
		$logo = (int) get_theme_mod( 'custom_logo' );
		if ( isset( $want[ $logo ] ) ) {
			$out[ $logo ][] = 'site logo';
		}
		// Open picker rows that offer it as one of the product's own pictures.
		foreach ( $wpdb->get_results( 'SELECT product_id, chosen FROM ' . Enrichment::table() . " WHERE kind = 'image' AND status IN ('proposed','accepted') AND chosen LIKE '%att:%'", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- no input
			foreach ( (array) json_decode( (string) $r['chosen'], true ) as $tok ) {
				if ( is_string( $tok ) && strpos( $tok, 'att:' ) === 0 && isset( $want[ (int) substr( $tok, 4 ) ] ) ) {
					$out[ (int) substr( $tok, 4 ) ][] = 'picked for #' . $r['product_id'];
				}
			}
		}
		// Attached to something that isn't a product (a Bricks project, a page).
		foreach ( $wpdb->get_results( "SELECT a.ID, p.post_type, p.ID parent FROM {$wpdb->posts} a JOIN {$wpdb->posts} p ON p.ID = a.post_parent WHERE a.ID IN ({$in}) AND p.post_type NOT IN ('product','product_variation')", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL -- ints
			$out[ (int) $r['ID'] ][] = "attached to {$r['post_type']} #{$r['parent']}";
		}
		// Any of its files named by URL anywhere: page content, Bricks/SEO meta, term meta, options.
		$named = self::uploads_paths_in_use();
		foreach ( $atts as $a ) {
			foreach ( self::files_of( $a ) as $f ) {
				if ( isset( $named[ $f ] ) ) {
					$out[ $a ][] = 'file named in content: ' . $f;
					break;
				}
			}
		}
		return array_map( static fn( $r ) => array_values( array_unique( $r ) ), $out );
	}

	/** Upload-relative paths that page content, post meta, term meta or options name by URL (once per request). @return array<string,true> */
	private static function uploads_paths_in_use(): array {
		static $paths = null;
		if ( $paths !== null ) {
			return $paths;
		}
		global $wpdb;
		$paths = [];
		$scan  = static function ( array $values ) use ( &$paths ): void {
			foreach ( $values as $v ) {
				if ( preg_match_all( '#wp-content/uploads/([^"\'\s<>()\\\\?]+\.(?:jpe?g|png|webp|gif|avif|svg))#i', str_replace( '\\/', '/', (string) $v ), $m ) ) {
					foreach ( $m[1] as $u ) {
						$paths[ rawurldecode( $u ) ] = true;
					}
				}
			}
		};
		$scan( $wpdb->get_col( "SELECT post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','attachment') AND post_content LIKE '%wp-content/uploads/%'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		$scan( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_value LIKE '%wp-content/uploads/%'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		$scan( $wpdb->get_col( "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_value LIKE '%wp-content/uploads/%'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		$scan( $wpdb->get_col( "SELECT option_value FROM {$wpdb->options} WHERE option_value LIKE '%wp-content/uploads/%'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		return $paths;
	}

	/* ── Step 2: quarantine ────────────────────────────────────────────── */

	/**
	 * Quarantine released rows whose wait is over (CLI). Referenced ones are
	 * kept, with the reason. @return array{quarantined:int,kept:int,bytes:int,dir:string}
	 */
	public static function quarantine_due( int $max = 200, bool $dry = false ): array {
		$out = [ 'quarantined' => 0, 'kept' => 0, 'bytes' => 0, 'dir' => '' ];
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $out;
		}
		self::maybe_install();
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE state = 'released' AND release_after <= UTC_TIMESTAMP() ORDER BY release_after LIMIT %d", $max ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $rows ) {
			return $out;
		}
		$refs = self::references( array_map( 'intval', array_column( array_filter( $rows, static fn( $r ) => $r['kind'] === 'attachment' ), 'attachment_id' ) ) );
		$dir  = trailingslashit( self::base_dir() ) . self::DIR_PREFIX . gmdate( 'Y-m-d' );
		$up   = trailingslashit( wp_get_upload_dir()['basedir'] );
		$out['dir'] = $dir;
		foreach ( $rows as $r ) {
			$att = (int) $r['attachment_id'];
			if ( $r['kind'] === 'attachment' && ! empty( $refs[ $att ] ) ) {
				$out['kept']++;
				if ( ! $dry ) {
					$wpdb->update( $t, [ 'state' => 'kept', 'reason' => mb_substr( implode( '; ', $refs[ $att ] ), 0, 120 ) ], [ 'attachment_id' => $att, 'kind' => 'attachment' ] );
				}
				continue;
			}
			if ( $r['kind'] === 'attachment' && get_post_type( $att ) !== 'attachment' ) {
				if ( ! $dry ) {
					$wpdb->update( $t, [ 'state' => 'kept', 'reason' => 'attachment already gone' ], [ 'attachment_id' => $att, 'kind' => 'attachment' ] );
				}
				continue;
			}
			$files = $r['kind'] === 'sizes' ? (array) ( json_decode( (string) $r['files'], true )['rel'] ?? [] ) : self::files_of( $att );
			$bytes = self::sum_bytes( $files );
			$out['quarantined']++;
			$out['bytes'] += $bytes;
			if ( $dry ) {
				continue;
			}
			wp_mkdir_p( $dir . '/files' );
			if ( $r['kind'] === 'attachment' ) {
				// Everything needed to put the attachment back exactly.
				global $wpdb;
				$record = [
					'id'       => $att,
					'post'     => get_post( $att, ARRAY_A ),
					'meta'     => $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $att ), ARRAY_A ),
					'trim'     => $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}mmi_image_trim WHERE attachment_id = %d", $att ), ARRAY_A ),
					'products' => json_decode( (string) $r['products'], true ),
				];
				file_put_contents( $dir . '/attachments.jsonl', wp_json_encode( $record ) . "\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			$moved = [];
			foreach ( $files as $f ) {
				$to = $dir . '/files/' . $f;
				wp_mkdir_p( dirname( $to ) );
				if ( is_file( $up . $f ) && rename( $up . $f, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					$moved[] = $f;
					file_put_contents( $dir . '/manifest.tsv', "{$att}\t{$r['kind']}\t" . (int) filesize( $to ) . "\t{$f}\n", FILE_APPEND ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
			if ( $r['kind'] === 'attachment' ) {
				$wpdb->delete( $wpdb->prefix . 'mmi_image_trim', [ 'attachment_id' => $att ] );
				wp_delete_attachment( $att, true ); // its files are in quarantine already
			}
			$wpdb->update( $t, [ 'state' => 'quarantined', 'quarantined_at' => current_time( 'mysql', true ), 'quarantine_dir' => $dir, 'bytes' => $bytes, 'files' => $r['kind'] === 'sizes' ? $r['files'] : wp_json_encode( [ 'rel' => $moved ] ) ], [ 'attachment_id' => $att, 'kind' => $r['kind'] ] );
		}
		if ( ! $dry && $out['quarantined'] ) {
			self::write_readme( $dir );
			Module::info( 'Replaced product pictures moved to quarantine', $out );
			if ( class_exists( 'MMI_Audit_Log' ) ) {
				\MMI_Audit_Log::record( 'mmi-admin', 'product_images.quarantine', [ 'object_type' => 'attachment', 'details' => $out + [ 'ids' => array_slice( array_map( 'intval', array_column( $rows, 'attachment_id' ) ), 0, 500 ) ] ] );
			}
		}
		return $out;
	}

	private static function write_readme( string $dir ): void {
		$f = $dir . '/README.txt';
		if ( is_file( $f ) ) {
			return;
		}
		file_put_contents( // phpcs:ignore WordPress.WP.AlternativeFunctions
			$f,
			"Product pictures replaced by mmi-admin's picture runner (PRODUCT_IMAGE_REPLACEMENT_HANDOFF.md).\n"
			. "manifest.tsv: attachment ID, kind, bytes, path under wp-content/uploads.\n"
			. "attachments.jsonl: each deleted attachment's post row and meta.\n"
			. "Restore one: wp mmpt images restore <attachment ID>. Restore a product: wp mmpt images revert <product ID>.\n"
			. 'Permanent delete only after ' . self::GRACE_DAYS . " days and the owner's go-ahead: wp mmpt images purge " . basename( $dir ) . " --yes\n"
		);
	}

	/* ── Step 3: permanent delete ──────────────────────────────────────── */

	/**
	 * Delete a quarantine folder for good (CLI, owner's go-ahead). Refused
	 * while any row in it is younger than GRACE_DAYS.
	 *
	 * @return array{deleted:bool,message:string,rows:int,bytes:int}
	 */
	public static function purge( string $folder ): array {
		self::maybe_install();
		global $wpdb;
		$dir = trailingslashit( self::base_dir() ) . basename( $folder );
		if ( strpos( basename( $folder ), self::DIR_PREFIX ) !== 0 || ! is_dir( $dir ) ) {
			return [ 'deleted' => false, 'message' => 'No such quarantine folder.', 'rows' => 0, 'bytes' => 0 ];
		}
		$t     = self::table();
		$young = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE quarantine_dir = %s AND state = 'quarantined' AND quarantined_at > UTC_TIMESTAMP() - INTERVAL %d DAY", $dir, self::GRACE_DAYS ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $young ) {
			return [ 'deleted' => false, 'message' => "{$young} items in it are younger than " . self::GRACE_DAYS . ' days.', 'rows' => 0, 'bytes' => 0 ];
		}
		$c = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(bytes),0) b FROM {$t} WHERE quarantine_dir = %s AND state = 'quarantined'", $dir ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			$f->isDir() ? rmdir( $f->getPathname() ) : unlink( $f->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET state = 'deleted', deleted_at = UTC_TIMESTAMP() WHERE quarantine_dir = %s AND state = 'quarantined'", $dir ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$out = [ 'deleted' => true, 'message' => '', 'rows' => (int) $c['n'], 'bytes' => (int) $c['b'] ];
		Module::info( 'Quarantined product pictures deleted', $out + [ 'dir' => $dir ] );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-admin', 'product_images.delete', [ 'object_type' => 'quarantine', 'object_id' => basename( $dir ), 'details' => $out ] );
		}
		return $out;
	}

	/* ── Undo ──────────────────────────────────────────────────────────── */

	/**
	 * Put a quarantined attachment (or its dropped sizes) back, with its
	 * original ID (CLI). @return true|\WP_Error
	 */
	public static function restore( int $att, string $kind = 'attachment' ) {
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE attachment_id = %d AND kind = %s", $att, $kind ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( ! $row ) {
			return new \WP_Error( 'mmpt_restore', 'Nothing recorded for this attachment.' );
		}
		if ( $row['state'] === 'released' || $row['state'] === 'kept' ) {
			$wpdb->update( $t, [ 'state' => 'restored' ], [ 'attachment_id' => $att, 'kind' => $kind ] );
			return true; // never moved
		}
		if ( $row['state'] !== 'quarantined' ) {
			return new \WP_Error( 'mmpt_restore', 'It was deleted for good on ' . $row['deleted_at'] . '.' );
		}
		if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return new \WP_Error( 'mmpt_restore_cli', 'Quarantined files are restored by the background runner.' );
		}
		$dir   = (string) $row['quarantine_dir'];
		$up    = trailingslashit( wp_get_upload_dir()['basedir'] );
		$files = (array) ( json_decode( (string) $row['files'], true )['rel'] ?? [] );
		if ( $kind === 'attachment' ) {
			$record = null;
			foreach ( file( $dir . '/attachments.jsonl', FILE_IGNORE_NEW_LINES ) ?: [] as $line ) {
				$r = json_decode( $line, true );
				if ( (int) ( $r['id'] ?? 0 ) === $att ) {
					$record = $r;
				}
			}
			if ( ! $record || empty( $record['post'] ) ) {
				return new \WP_Error( 'mmpt_restore', 'Its record is missing from ' . $dir . '/attachments.jsonl.' );
			}
			if ( get_post( $att ) ) {
				return new \WP_Error( 'mmpt_restore', "Post #{$att} exists again; not overwriting it." );
			}
			$post              = $record['post'];
			$post['import_id'] = $att;
			unset( $post['ID'], $post['guid'] );
			$new = wp_insert_post( wp_slash( $post ), true );
			if ( is_wp_error( $new ) || (int) $new !== $att ) {
				return is_wp_error( $new ) ? $new : new \WP_Error( 'mmpt_restore', "Restored as #{$new}, not #{$att}." );
			}
			$wpdb->update( $wpdb->posts, [ 'guid' => (string) $record['post']['guid'] ], [ 'ID' => $att ] );
			foreach ( (array) $record['meta'] as $m ) {
				$wpdb->insert( $wpdb->postmeta, [ 'post_id' => $att, 'meta_key' => $m['meta_key'], 'meta_value' => $m['meta_value'] ] );
			}
			if ( ! empty( $record['trim'] ) ) {
				$wpdb->replace( $wpdb->prefix . 'mmi_image_trim', $record['trim'] );
			}
			clean_post_cache( $att );
		} else {
			$meta = (array) wp_get_attachment_metadata( $att, true );
			foreach ( (array) ( json_decode( (string) $row['files'], true )['sizes'] ?? [] ) as $name => $entry ) {
				$meta['sizes'][ $name ] = $entry;
			}
			wp_update_attachment_metadata( $att, $meta );
		}
		foreach ( $files as $f ) {
			if ( is_file( $dir . '/files/' . $f ) ) {
				wp_mkdir_p( dirname( $up . $f ) );
				rename( $dir . '/files/' . $f, $up . $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		$wpdb->update( $t, [ 'state' => 'restored', 'quarantine_dir' => '' ], [ 'attachment_id' => $att, 'kind' => $kind ] );
		Module::info( 'Quarantined picture restored', [ 'attachment_id' => $att, 'kind' => $kind ] );
		return true;
	}

	/* ── Reporting ─────────────────────────────────────────────────────── */

	/** @return array<string,array{n:int,bytes:int}> by state */
	public static function totals(): array {
		self::maybe_install();
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( 'SELECT state, COUNT(*) n, COALESCE(SUM(bytes),0) b FROM ' . self::table() . ' GROUP BY state', ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[ $r['state'] ] = [ 'n' => (int) $r['n'], 'bytes' => (int) $r['b'] ];
		}
		return $out;
	}

	/** Quarantine folders and whether each may be deleted yet. @return array<int,array{dir:string,n:int,bytes:int,oldest:string,newest:string,purge_from:string}> */
	public static function folders(): array {
		self::maybe_install();
		global $wpdb;
		$out = [];
		foreach ( $wpdb->get_results( "SELECT quarantine_dir d, COUNT(*) n, COALESCE(SUM(bytes),0) b, MIN(quarantined_at) o, MAX(quarantined_at) w FROM " . self::table() . " WHERE state = 'quarantined' GROUP BY quarantine_dir ORDER BY o", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL
			$out[] = [ 'dir' => basename( (string) $r['d'] ), 'n' => (int) $r['n'], 'bytes' => (int) $r['b'], 'oldest' => (string) $r['o'], 'newest' => (string) $r['w'], 'purge_from' => gmdate( 'Y-m-d H:i', strtotime( $r['w'] . ' UTC' ) + self::GRACE_DAYS * DAY_IN_SECONDS ) ];
		}
		return $out;
	}
}
