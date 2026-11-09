<?php
/**
 * `wp mmpt images …` — product pictures (Pictures, PictureMatch, MediaRelease;
 * PRODUCT_IMAGE_REPLACEMENT_HANDOFF.md).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesCli {

	/**
	 * What every product with pictures would get, and what its files weigh. Reads stored pages only; writes a CSV.
	 *
	 * ## OPTIONS
	 * [<id>...]
	 * : Only these products.
	 */
	public function census( $args ): void {
		\WP_CLI::log( wp_json_encode( Pictures::census( array_map( 'intval', $args ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Show the plan for products (nothing is written).
	 *
	 * ## OPTIONS
	 * <id>...
	 */
	public function plan( $args ): void {
		foreach ( array_map( 'intval', $args ) as $id ) {
			\WP_CLI::log( "#{$id} " . wp_json_encode( Pictures::process( $id, true ), JSON_UNESCAPED_SLASHES ) );
		}
	}

	/**
	 * Queue products and start the runner (opens a pass).
	 *
	 * ## OPTIONS
	 * [<id>...]
	 * : These products.
	 * [--host=<host>]
	 * : Every product whose verified vendor URL is on this site (www. ignored).
	 * [--all]
	 * : Every published product with pictures.
	 * [--again]
	 * : Also products whose pictures were already changed.
	 * [--queue-only]
	 * : Queue without starting the runner.
	 * [--vendor-only]
	 * : Only products the runner would give their vendor page's pictures now (never an in-place re-save).
	 */
	public function queue( $args, $assoc ): void {
		global $wpdb;
		$ids = array_map( 'intval', $args );
		if ( ! empty( $assoc['host'] ) ) {
			$host = preg_replace( '/^www\./', '', strtolower( (string) $assoc['host'] ) );
			$ids  = array_merge(
				$ids,
				array_map(
					'intval',
					$wpdb->get_col(
						$wpdb->prepare(
							"SELECT p.ID FROM {$wpdb->posts} p
							 JOIN {$wpdb->postmeta} u ON u.post_id = p.ID AND u.meta_key = 'vendor_product_url'
							 JOIN {$wpdb->postmeta} v ON v.post_id = p.ID AND v.meta_key = 'vendor_product_url_verified' AND UPPER( v.meta_value ) IN ('TRUE','1')
							 WHERE p.post_type = 'product' AND p.post_status = 'publish'
							   AND ( u.meta_value LIKE %s OR u.meta_value LIKE %s )",
							'%://' . $wpdb->esc_like( $host ) . '/%',
							'%://www.' . $wpdb->esc_like( $host ) . '/%'
						)
					)
				)
			);
		}
		if ( ! empty( $assoc['all'] ) ) {
			$ids = array_map( 'intval', $wpdb->get_col( "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} t ON t.post_id = p.ID AND t.meta_key = '_thumbnail_id' AND t.meta_value > 0 WHERE p.post_type = 'product' AND p.post_status = 'publish'" ) ); // phpcs:ignore WordPress.DB.PreparedSQL -- no input
		}
		if ( ! empty( $assoc['vendor-only'] ) ) {
			$given = count( array_unique( $ids ) );
			$ids   = array_values( array_filter( array_unique( $ids ), static fn( $id ) => Pictures::plan( (int) $id )['mode'] === 'vendor' ) );
			\WP_CLI::log( count( $ids ) . " of {$given} products would get their vendor page's pictures." );
		}
		if ( ! $ids ) {
			\WP_CLI::error( 'No products given.' );
		}
		$n = Pictures::enqueue( $ids, 'manual', ! empty( $assoc['again'] ) );
		if ( ! empty( $assoc['vendor-only'] ) ) {
			// The runner then skips any of them whose page can no longer give pictures, instead of re-saving its own.
			foreach ( array_chunk( array_map( 'intval', $ids ), 500 ) as $chunk ) {
				$wpdb->query( 'UPDATE ' . Pictures::table() . " SET mode = 'vendor' WHERE status = 'queued' AND product_id IN (" . implode( ',', $chunk ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL -- ints
			}
		}
		\WP_CLI::log( "Queued {$n} of " . count( array_unique( $ids ) ) . ' products.' );
		if ( empty( $assoc['queue-only'] ) ) {
			\WP_CLI::log( Pictures::kick() ? 'Runner started.' : 'Runner not started (already running, daily limit reached or nothing to do).' );
		}
	}

	/**
	 * The picture runner. Started detached by queue/the tab; safe to run by hand.
	 *
	 * ## OPTIONS
	 * [--limit=<n>]
	 * : Stop after this many products.
	 */
	public function run( $args, $assoc ): void {
		\WP_CLI::log( wp_json_encode( Pictures::run( (int) ( $assoc['limit'] ?? 0 ) ) ) );
	}

	/** Counts, the runner's state and what could pause it. */
	public function status(): void {
		\WP_CLI::log( wp_json_encode( Pictures::summary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Put products' pictures back.
	 *
	 * ## OPTIONS
	 * <id>...
	 */
	public function revert( $args ): void {
		foreach ( array_map( 'intval', $args ) as $id ) {
			\WP_CLI::log( "#{$id} " . wp_json_encode( Pictures::revert( $id ) ) );
		}
	}

	/**
	 * Move released media whose wait is over into quarantine (the runner does this too).
	 *
	 * ## OPTIONS
	 * [--dry-run]
	 * [--max=<n>]
	 */
	public function release( $args, $assoc ): void {
		\WP_CLI::log( wp_json_encode( MediaRelease::quarantine_due( (int) ( $assoc['max'] ?? 200 ), ! empty( $assoc['dry-run'] ) ), JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Delete a quarantine folder for good. Only with the owner's go-ahead, and only after the grace period.
	 *
	 * ## OPTIONS
	 * [<folder>]
	 * : media-replaced-YYYY-MM-DD. Without it, lists the folders.
	 * [--yes]
	 */
	public function purge( $args, $assoc ): void {
		if ( ! $args ) {
			\WP_CLI::log( wp_json_encode( MediaRelease::folders(), JSON_PRETTY_PRINT ) );
			return;
		}
		if ( empty( $assoc['yes'] ) ) {
			\WP_CLI::error( 'Permanent: add --yes once the owner has said to delete this folder.' );
		}
		\WP_CLI::log( wp_json_encode( MediaRelease::purge( (string) $args[0] ) ) );
	}

	/**
	 * Restore a quarantined attachment with its original ID.
	 *
	 * ## OPTIONS
	 * <attachment>...
	 * [--kind=<kind>]
	 * : attachment (default) or sizes.
	 */
	public function restore( $args, $assoc ): void {
		foreach ( array_map( 'intval', $args ) as $a ) {
			$r = MediaRelease::restore( $a, (string) ( $assoc['kind'] ?? 'attachment' ) );
			\WP_CLI::log( "#{$a} " . ( is_wp_error( $r ) ? $r->get_error_message() : 'restored' ) );
		}
	}

	/**
	 * Drop the 768/600 copies of standard pictures (owner D3); their files are released.
	 *
	 * ## OPTIONS
	 * [--dry-run]
	 *
	 * @subcommand strip-sizes
	 */
	public function strip_sizes( $args, $assoc ): void {
		\WP_CLI::log( wp_json_encode( Pictures::strip_sizes( ! empty( $assoc['dry-run'] ) ) ) );
	}

	/**
	 * The catch-up (owner 2026-10-18): pictures the replacing pass dropped that a product no longer has in any copy.
	 * Lists them (CSV in the private log folder; --sheet draws them); --apply puts them back.
	 *
	 * ## OPTIONS
	 * [<id>...]
	 * : Only these products (default: every product vendor pictures replaced).
	 * [--no-ask]
	 * : Fingerprints only: no Claude calls (doubtful pairs then count as lost and are restored).
	 * [--apply]
	 * : Restore the lost pictures. Only once the owner has seen the list.
	 * [--sheet=<png>]
	 * : Draw each product with lost pictures: what it has, then what it lost.
	 */
	public function dropped( $args, $assoc ): void {
		global $wpdb;
		$ids = array_map( 'intval', $args ) ?: array_map( 'intval', $wpdb->get_col( 'SELECT product_id FROM ' . Pictures::table() . " WHERE status = 'applied' AND mode = 'vendor' ORDER BY product_id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ask = empty( $assoc['no-ask'] );
		$dir = mmi_shared_lib_private_subdir( 'mmpt-logs' );
		$csv = $dir !== '' ? $dir . 'pictures-dropped-' . gmdate( 'Ymd-His' ) . '.csv' : '';
		$fh  = $csv !== '' ? fopen( $csv, 'w' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh ) {
			fputcsv( $fh, [ 'product_id', 'title', 'attachment', 'state', 'kind', 'same_as' ] );
		}
		$sum   = [ 'products' => 0, 'with_lost' => 0, 'lost' => 0, 'covered' => 0, 'duplicate' => 0, 'deleted' => 0, 'unreadable' => 0, 'not_a_picture' => 0, 'doubt' => 0, 'restored' => 0, 'left' => 0, 'errors' => [] ];
		$sheet = [];
		foreach ( $ids as $id ) {
			$r = Pictures::dropped( $id, $ask );
			$sum['products']++;
			$sum['doubt'] += (int) ( $r['doubt'] ?? 0 );
			foreach ( $r['gone'] as $g ) {
				$sum[ $g['kind'] ]++;
				if ( $fh ) {
					fputcsv( $fh, [ $id, html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $g['att'], $g['state'], $g['kind'], $g['of'] ?? '' ] );
				}
			}
			if ( ! $r['lost'] ) {
				continue;
			}
			$sum['with_lost']++;
			$sheet[] = $r;
			\WP_CLI::log( sprintf( '#%d %s: lost %s', $id, html_entity_decode( get_the_title( $id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), '#' . implode( ', #', $r['lost'] ) ) );
			if ( ! empty( $assoc['apply'] ) ) {
				$res              = Pictures::restore_dropped( $id, $r['lost'] );
				$sum['restored'] += count( $res['restored'] );
				$sum['left']     += count( $res['left'] );
				$sum['errors']    = array_merge( $sum['errors'], array_map( static fn( $e ) => "#{$id} {$e}", $res['errors'] ) );
				if ( class_exists( 'MMI_Audit_Log' ) ) {
					\MMI_Audit_Log::record( 'mmi-admin', 'product_images.restore_dropped', [ 'object_type' => 'product', 'object_id' => $id, 'outcome' => $res['errors'] ? 'failure' : 'success', 'details' => $res ] );
				}
			}
		}
		$sum['lost'] += $sum['unreadable'];
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! empty( $assoc['sheet'] ) && $sheet ) {
			self::sheet( $sheet, (string) $assoc['sheet'] );
		}
		\WP_CLI::log( wp_json_encode( $sum + [ 'csv' => $csv, 'calls_left_today' => PictureMatch::calls_left() ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/** One row per product: its pictures now, then (red border) the ones it lost. */
	private static function sheet( array $rows, string $png ): void {
		$t   = 96;
		$pad = 4;
		$w   = 18 * ( $t + $pad );
		$im  = imagecreatetruecolor( $w, count( $rows ) * ( $t + 22 ) );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 235, 235, 235 ) );
		$red = imagecolorallocate( $im, 214, 54, 56 );
		foreach ( $rows as $k => $r ) {
			$y = $k * ( $t + 22 );
			imagestring( $im, 3, 4, $y + 2, '#' . $r['id'] . ' ' . mb_substr( html_entity_decode( get_the_title( $r['id'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), 0, 90 ), 0 );
			$files = array_map( static fn( $a ) => [ (string) get_attached_file( $a ), false ], Pictures::current( $r['id'] ) );
			foreach ( $r['gone'] as $g ) {
				if ( in_array( $g['att'], $r['lost'], true ) ) {
					$files[] = [ $g['file'], true ];
				}
			}
			foreach ( array_slice( $files, 0, 18 ) as $i => [ $f, $lost ] ) {
				$src = $f !== '' && is_readable( $f ) ? @imagecreatefromstring( (string) file_get_contents( $f ) ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
				$x   = $i * ( $t + $pad );
				if ( $src ) {
					$s = min( $t / imagesx( $src ), $t / imagesy( $src ) );
					imagecopyresampled( $im, $src, $x, $y + 18, 0, 0, (int) ( imagesx( $src ) * $s ), (int) ( imagesy( $src ) * $s ), imagesx( $src ), imagesy( $src ) );
					imagedestroy( $src );
				}
				if ( $lost ) {
					imagerectangle( $im, $x, $y + 18, $x + $t - 1, $y + 18 + $t - 1, $red );
					imagerectangle( $im, $x + 1, $y + 19, $x + $t - 2, $y + 18 + $t - 2, $red );
				}
			}
		}
		imagepng( $im, $png );
		imagedestroy( $im );
	}

	/** Remove products still waiting in the queue. */
	public function stop(): void {
		\WP_CLI::log( 'Removed ' . Pictures::clear_pending() . ' waiting products.' );
	}
}
