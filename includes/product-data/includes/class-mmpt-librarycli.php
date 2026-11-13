<?php
/**
 * `wp mmpt library`: the reference library (Library) from the command line.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

defined( 'ABSPATH' ) || exit;

class LibraryCli {

	/**
	 * List, add or remove reference sites (pages opened there in a connected Chrome are kept).
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : list (default), add or remove.
	 *
	 * [<site>]
	 * : A host, or a host with the start of a path: help.example.com/articles/
	 *
	 * ## EXAMPLES
	 *
	 *     wp mmpt library sites
	 *     wp mmpt library sites add help.example.com/articles/
	 *
	 * @param array $args Positional arguments.
	 */
	public function sites( $args ): void {
		$action = $args[0] ?? 'list';
		$site   = (string) ( $args[1] ?? '' );
		$list   = Library::sites();
		if ( $action === 'add' || $action === 'remove' ) {
			$e = Library::normalize_site( $site );
			if ( $e === '' ) {
				\WP_CLI::error( "“{$site}” isn’t a site address (or is this store’s own)." );
			}
			$list = $action === 'add' ? array_merge( $list, [ $e ] ) : array_values( array_diff( $list, [ $e ] ) );
			$list = Library::save_sites( $list );
			\WP_CLI::success( ( $action === 'add' ? 'Added ' : 'Removed ' ) . $e );
		} elseif ( $action !== 'list' ) {
			\WP_CLI::error( 'Use list, add or remove.' );
		}
		$counts = Library::counts();
		$rows   = array_map( static fn( $s ) => [ 'site' => $s, 'pages' => $counts[ $s ]['pages'] ?? 0, 'last_kept' => $counts[ $s ]['last'] ?? '' ], $list );
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'site', 'pages', 'last_kept' ] );
	}

	/**
	 * Search kept pages (full text over title and text).
	 *
	 * ## OPTIONS
	 *
	 * <query>
	 * : Words to find. With --boolean, MySQL boolean syntax ("exact phrase", +must, -not).
	 *
	 * [--site=<site>]
	 * : Only this reference site (as listed by `sites`).
	 *
	 * [--limit=<n>]
	 * : Rows. Default 20.
	 *
	 * [--boolean]
	 * : Boolean full-text mode.
	 *
	 * [--format=<format>]
	 * : table (default), json or csv.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function search( $args, $assoc ): void {
		$res  = Library::search( (string) $args[0], (string) ( $assoc['site'] ?? '' ), (int) ( $assoc['limit'] ?? 20 ), 0, isset( $assoc['boolean'] ) );
		$fmt  = (string) ( $assoc['format'] ?? 'table' );
		$rows = array_map( static fn( $r ) => [ 'id' => $r['id'], 'title' => $r['title'], 'updated_on' => $r['updated_on'], 'score' => $r['score'], 'url' => $r['url'], 'snippet' => $r['snippet'] ], $res['rows'] );
		if ( $fmt === 'table' ) {
			\WP_CLI::log( sprintf( '%d page(s) match.', $res['total'] ) );
			$rows = array_map( static fn( $r ) => array_merge( $r, [ 'snippet' => mb_substr( $r['snippet'], 0, 120 ) ] ), $rows );
		}
		\WP_CLI\Utils\format_items( $fmt, $rows, [ 'id', 'title', 'updated_on', 'score', 'url', 'snippet' ] );
	}

	/**
	 * Kept pages, newest first.
	 *
	 * ## OPTIONS
	 *
	 * [--site=<site>]
	 * : Only this reference site.
	 *
	 * [--limit=<n>]
	 * : Rows. Default 50.
	 *
	 * [--format=<format>]
	 * : table (default), json or csv.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function list( $args, $assoc ): void {
		$res = Library::search( '', (string) ( $assoc['site'] ?? '' ), (int) ( $assoc['limit'] ?? 50 ) );
		\WP_CLI\Utils\format_items( (string) ( $assoc['format'] ?? 'table' ), $res['rows'], [ 'id', 'title', 'updated_on', 'kept_at', 'versions', 'url' ] );
	}

	/**
	 * One kept page: its fields and text, or an earlier version's text.
	 *
	 * ## OPTIONS
	 *
	 * <page>
	 * : The page's id, or its address.
	 *
	 * [--version=<id>]
	 * : An earlier version's id (listed under "Earlier versions").
	 *
	 * [--format=<format>]
	 * : text (default) or json.
	 *
	 * @param array $args  Positional arguments.
	 * @param array $assoc Options.
	 */
	public function get( $args, $assoc ): void {
		$ref = (string) $args[0];
		$id  = ctype_digit( $ref ) ? (int) $ref : Library::id_for_url( $ref );
		$row = $id ? Library::get( $id ) : null;
		if ( ! $row ) {
			\WP_CLI::error( 'That page isn’t in the library.' );
		}
		if ( ! empty( $assoc['version'] ) ) {
			$row['text']  = Library::version_text( (int) $assoc['version'] );
			$row['title'] .= ' (earlier version ' . (int) $assoc['version'] . ')';
		}
		if ( ( $assoc['format'] ?? 'text' ) === 'json' ) {
			\WP_CLI::line( (string) wp_json_encode( $row, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		\WP_CLI::line( '# ' . $row['title'] );
		\WP_CLI::line( $row['url'] );
		\WP_CLI::line( sprintf( 'Page updated: %s · first kept %s · last kept %s · kept %d times', $row['updated_on'] ?: 'not stated', $row['first_seen'], $row['kept_at'], $row['kept_count'] ) );
		foreach ( $row['fields'] as $k => $v ) {
			\WP_CLI::line( "{$k}: {$v}" );
		}
		foreach ( $row['earlier'] ?? [] as $e ) {
			\WP_CLI::line( sprintf( 'Earlier version %d: kept %s, replaced %s (%d characters)', $e['id'], $e['kept_at'], $e['replaced_at'], $e['chars'] ) );
		}
		\WP_CLI::line( '' );
		\WP_CLI::line( (string) $row['text'] );
	}

	/**
	 * Read a saved HTML file as the library would, without keeping it (to check what a page gives).
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : An HTML file.
	 *
	 * <url>
	 * : The address it came from.
	 *
	 * @param array $args Positional arguments.
	 */
	public function read( $args ): void {
		$html = (string) file_get_contents( (string) $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local file named on the command line
		$page = Library::read( $html, (string) $args[1] );
		$text = $page['text'];
		unset( $page['text'] );
		\WP_CLI::line( (string) wp_json_encode( $page, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		\WP_CLI::line( sprintf( '--- text (%d characters) ---', mb_strlen( $text ) ) );
		\WP_CLI::line( mb_substr( $text, 0, 3000 ) );
	}
}
