<?php
/**
 * MMI_Media_Helper — shared source-URL dedup + sideload (bundled/vendored copy).
 *
 * Identical to the original mmi-hub/includes/helpers/class-media-helper.php
 * this was vendored from. Already fully self-contained ($wpdb + WP core media
 * functions only, no mmi-hub-specific dependency) — vendored unchanged. The
 * original docblock's rationale for living only in mmi-hub ("every MMI
 * plugin already hard-depends on mmi-hub") no longer holds once a plugin's
 * MMI_Hub gate is removed — see ADR-0006. Do not hand-edit this file in a
 * single plugin; edit the canonical source and re-sync to every plugin that
 * bundles it.
 *
 * @package MannMade\SharedLib
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'MMI_SHARED_LIB_STANDALONE_TEST' ) ) {
	exit;
}

class MMI_Media_Helper {

	/** Canonical postmeta key an attachment's real remote source URL is stamped under. */
	const SOURCE_URL_META_KEY = '_mmi_source_url';

	/**
	 * Meta keys older, plugin-specific dedup attempts used before this class
	 * existed — checked as a read-only fallback so an attachment imported by
	 * an earlier plugin version is still found (and not needlessly
	 * re-downloaded) rather than requiring a one-time data migration.
	 * Nothing writes these keys going forward.
	 */
	const LEGACY_SOURCE_URL_META_KEYS = array( '_mmi_xchange_source_url' );

	/**
	 * Look up an existing media-library attachment by its exact remote
	 * source URL. Never a `guid = %s` comparison — sideloaded files never
	 * get the remote URL as their guid.
	 *
	 * @param string $url
	 * @return int|null Attachment ID, or null if no match.
	 */
	public static function find_by_source_url( string $url ): ?int {
		$url = trim( $url );
		if ( $url === '' ) {
			return null;
		}

		global $wpdb;

		foreach ( array_merge( array( self::SOURCE_URL_META_KEY ), self::LEGACY_SOURCE_URL_META_KEYS ) as $meta_key ) {
			$attachment_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				$meta_key,
				$url
			) );
			if ( $attachment_id ) {
				return (int) $attachment_id;
			}
		}

		return null;
	}

	/**
	 * Stamp an attachment with the remote URL it was actually sideloaded
	 * from, so a later run's find_by_source_url() can recognize it.
	 *
	 * @param int    $attachment_id
	 * @param string $url
	 */
	public static function tag_source_url( int $attachment_id, string $url ): void {
		if ( ! $attachment_id || trim( $url ) === '' ) {
			return;
		}
		update_post_meta( $attachment_id, self::SOURCE_URL_META_KEY, trim( $url ) );
	}

	/**
	 * Download a remote URL and sideload it as a real WP attachment
	 * associated with $post_id, tagging it with its source URL so a future
	 * call correctly dedups. Does NOT check find_by_source_url() first.
	 *
	 * @param string      $url
	 * @param int         $post_id  Parent post to associate the attachment with.
	 * @param string|null $filename Optional override for the sideloaded file's name.
	 * @return int|WP_Error Attachment ID, or the download/attach error.
	 */
	public static function sideload( string $url, int $post_id, ?string $filename = null ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		$file_array = array(
			'name'     => $filename ?: basename( wp_parse_url( $url, PHP_URL_PATH ) ?: $url ),
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		self::tag_source_url( $attachment_id, $url );

		return $attachment_id;
	}

	/* ── Ownership (Shape C — both mmi-data-pipeline and mmi-xchange-integration installed) ── */

	/**
	 * Postmeta key naming which process attached an image, e.g.
	 * 'xchange_vendor_import' or 'pipeline'. Only ever written once a second
	 * writer can genuinely be present.
	 */
	const OWNER_META_KEY = '_mmi_image_owner';

	/**
	 * @param int $attachment_id
	 * @return string The owner tag, or '' if untagged.
	 */
	public static function get_owner( int $attachment_id ): string {
		return $attachment_id ? (string) get_post_meta( $attachment_id, self::OWNER_META_KEY, true ) : '';
	}

	/**
	 * @param int    $attachment_id
	 * @param string $owner
	 */
	public static function set_owner( int $attachment_id, string $owner ): void {
		if ( ! $attachment_id || $owner === '' ) {
			return;
		}
		update_post_meta( $attachment_id, self::OWNER_META_KEY, $owner );
	}

	/**
	 * Delete an attachment a process is dropping from its own tracked set —
	 * but only if nothing else on the site still references it.
	 *
	 * @param int $attachment_id
	 * @return bool True if actually deleted, false if still referenced
	 *              elsewhere (or the ID was empty/invalid).
	 */
	public static function maybe_delete_orphan( int $attachment_id ): bool {
		if ( ! $attachment_id ) {
			return false;
		}

		global $wpdb;

		$as_thumbnail = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d LIMIT 1",
			$attachment_id
		) );
		if ( $as_thumbnail ) {
			return false;
		}

		// _product_image_gallery is a comma-separated ID string, never a
		// serialized array — an exact-value compare plus the 3 substring
		// shapes an ID can appear in within that string (start, end, middle)
		// covers every position without needing to load and explode every row.
		$as_gallery = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery'
               AND ( meta_value = %d OR meta_value LIKE %s OR meta_value LIKE %s OR meta_value LIKE %s )
             LIMIT 1",
			$attachment_id,
			$attachment_id . ',%',
			'%,' . $attachment_id,
			'%,' . $attachment_id . ',%'
		) );
		if ( $as_gallery ) {
			return false;
		}

		return (bool) wp_delete_attachment( $attachment_id, true );
	}
}
