<?php
/**
 * Reference library: help and knowledge-base pages kept as a person reads them.
 *
 * A store lists the sites it wants kept ("Reference sites": a host, optionally with a path, e.g.
 * "help.example.com/articles/"). When someone with the Chrome extension connected opens a page on one of those sites,
 * the extension sends the page as Chrome drew it (POST /ext/library op=keep), and it is stored here: its title, the
 * page's own "last updated" date, its labelled fields, its text, and the HTML it came from. Opening it again later
 * stores a changed text as a new version and keeps the old one.
 *
 * Nothing is fetched by the server or the extension on its own: only pages a person opened are kept. A site's own
 * robots rules and bot checks are left alone (a bot check is refused, PageStore::is_wall()).
 *
 * The kept pages are searched from the Chrome extension page (Reference library section), by REST (mmpt/v1/library)
 * and by WP-CLI (`wp mmpt library`).
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

defined( 'ABSPATH' ) || exit;

class Library {

	const DB_OPTION    = 'mmpt_library_db_version';
	const DB_VERSION   = '1';
	const SITES_OPTION = 'mmpt_library_sites';  // [ "host/path-prefix", … ]
	const MAX_SITES    = 50;
	const MIN_TEXT     = 200;                   // a page with less text than this is not kept (a search or empty page)
	const MAX_TEXT     = 2000000;
	const SNIPPET      = 160;                   // characters each side of a search match
	const LIST_MAX     = 200;                   // rows one search answers with
	const FIELD_LINES  = 60;                    // labelled fields are read from the opening lines only
	const DROP_TAGS    = [ 'script', 'style', 'noscript', 'template', 'svg', 'iframe', 'input', 'textarea', 'nav', 'header', 'footer', 'aside', 'button', 'select', 'canvas', 'object', 'video', 'audio' ];
	const BLOCK_TAGS   = [ 'p', 'div', 'section', 'article', 'main', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'ul', 'ol', 'dl', 'dt', 'dd', 'tr', 'table', 'thead', 'tbody', 'blockquote', 'pre', 'br', 'hr', 'figure', 'figcaption', 'details', 'summary' ];

	/* ── Tables ─────────────────────────────────────────────────────────── */

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_library';
	}

	public static function versions_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mmpt_library_versions';
	}

	public static function maybe_install(): void {
		if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t  = self::table();
		$v  = self::versions_table();
		$cc = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$t} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url_hash char(32) NOT NULL,
  url varchar(1000) NOT NULL,
  host varchar(190) NOT NULL,
  site varchar(255) NOT NULL DEFAULT '',
  title varchar(500) NOT NULL DEFAULT '',
  summary text,
  updated_on date DEFAULT NULL,
  fields text,
  text longtext,
  text_hash char(32) NOT NULL DEFAULT '',
  html_gz longblob,
  first_seen datetime NOT NULL,
  kept_at datetime NOT NULL,
  changed_at datetime NOT NULL,
  kept_count int(10) unsigned NOT NULL DEFAULT 1,
  versions int(10) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY url_hash (url_hash),
  KEY site (site, kept_at),
  FULLTEXT KEY words (title, text)
) {$cc};" );
		dbDelta( "CREATE TABLE {$v} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  page_id bigint(20) unsigned NOT NULL,
  title varchar(500) NOT NULL DEFAULT '',
  updated_on date DEFAULT NULL,
  text longtext,
  text_hash char(32) NOT NULL DEFAULT '',
  kept_at datetime NOT NULL,
  replaced_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY page (page_id, kept_at)
) {$cc};" );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $v ) ) === $v ) {
			update_option( self::DB_OPTION, self::DB_VERSION, false );
		}
	}

	/* ── Hooks and routes ───────────────────────────────────────────────── */

	public static function register_hooks(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'assets' ] );
	}

	/** The admin routes (cookie + nonce). The extension's own route is ext/library (ExtBridge::routes(), token). */
	public static function routes(): void {
		$admin = static fn() => current_user_can( Rest::CAP );
		register_rest_route( Rest::NS, '/library', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'rest_list' ] ] );
		register_rest_route( Rest::NS, '/library/page', [ 'methods' => 'GET', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'rest_page' ] ] );
		register_rest_route( Rest::NS, '/library/sites', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'rest_sites' ] ] );
		register_rest_route( Rest::NS, '/library/delete', [ 'methods' => 'POST', 'permission_callback' => $admin, 'callback' => [ __CLASS__, 'rest_delete' ] ] );
	}

	/* ── Reference sites ────────────────────────────────────────────────── */

	/** @return string[] "host/path-prefix" entries, as saved. */
	public static function sites(): array {
		$s = get_option( self::SITES_OPTION, [] );
		return is_array( $s ) ? array_values( array_filter( array_map( 'strval', $s ) ) ) : [];
	}

	/**
	 * An entry as typed ("https://Help.Example.com/articles/x?y" → "help.example.com/articles/x"), or '' when it isn't a
	 * site: no scheme, query or fragment; a leading "www." kept as typed (matching ignores it). This store's own host is
	 * refused: its pages are not reference pages.
	 */
	public static function normalize_site( string $raw ): string {
		$raw = trim( strtolower( $raw ) );
		$raw = (string) preg_replace( '#^[a-z][a-z0-9+.-]*://#', '', $raw );
		$raw = (string) preg_replace( '/[?#].*$/', '', $raw );
		$raw = rtrim( $raw, '*' );
		$pos = strpos( $raw, '/' );
		$host = $pos === false ? $raw : substr( $raw, 0, $pos );
		$path = $pos === false ? '' : substr( $raw, $pos );
		$host = (string) preg_replace( '/:\d+$/', '', $host );
		if ( ! preg_match( '/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $host ) ) {
			return '';
		}
		$own = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( self::bare( $host ) === self::bare( $own ) ) {
			return '';
		}
		$path = $path === '/' ? '' : (string) preg_replace( '#/{2,}#', '/', $path );
		return $host . $path;
	}

	/** @param string[] $list Entries to save. @return string[] what was saved. */
	public static function save_sites( array $list ): array {
		$seen = [];
		foreach ( $list as $raw ) {
			$e = self::normalize_site( (string) $raw );
			if ( $e !== '' ) {
				$seen[ $e ] = true; // keyed: each entry once, in the order given
			}
		}
		$out = array_slice( array_map( 'strval', array_keys( $seen ) ), 0, self::MAX_SITES );
		$was = self::sites();
		update_option( self::SITES_OPTION, $out, false );
		if ( $out !== $was && class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-data-pipeline', 'library.sites', [ 'object_type' => 'library', 'object_id' => 'sites', 'details' => [ 'was' => $was, 'now' => $out ] ] );
		}
		return $out;
	}

	private static function bare( string $host ): string {
		return (string) preg_replace( '/^www\./', '', strtolower( $host ) );
	}

	/** The entry an address falls under ('' when none): same host (www. or not) and the entry's path as its start. */
	public static function site_for( string $url ): string {
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['host'] ) || ! in_array( strtolower( (string) ( $p['scheme'] ?? '' ) ), [ 'http', 'https' ], true ) ) {
			return '';
		}
		$host = self::bare( (string) $p['host'] );
		$path = strtolower( (string) ( $p['path'] ?? '/' ) );
		$best = '';
		foreach ( self::sites() as $e ) {
			$pos  = strpos( $e, '/' );
			$eh   = self::bare( $pos === false ? $e : substr( $e, 0, $pos ) );
			$ep   = $pos === false ? '' : substr( $e, $pos );
			if ( $eh === $host && ( $ep === '' || strpos( $path, $ep ) === 0 ) && strlen( $e ) > strlen( $best ) ) {
				$best = $e; // the most specific entry wins
			}
		}
		return $best;
	}

	/** The address a page is kept under: no fragment, no tracking codes; its canonical address when on the same site entry. */
	private static function key_url( string $url, string $canonical, string $site ): string {
		$clean = static function ( string $u ): string {
			$u = (string) strtok( $u, '#' );
			$q = (string) wp_parse_url( $u, PHP_URL_QUERY );
			if ( $q !== '' ) {
				parse_str( $q, $args );
				foreach ( array_keys( $args ) as $k ) {
					if ( preg_match( '/^(utm_|fbclid$|gclid$|mc_|_hs|ref$)/i', (string) $k ) ) {
						unset( $args[ $k ] );
					}
				}
				$u = (string) strtok( $u, '?' ) . ( $args ? '?' . http_build_query( $args ) : '' );
			}
			return $u;
		};
		if ( $canonical !== '' && self::site_for( $canonical ) === $site ) {
			return $clean( $canonical );
		}
		return $clean( $url );
	}

	/* ── Keeping a page (the extension) ─────────────────────────────────── */

	/**
	 * POST /ext/library {op}. op sites: the reference sites, for the extension to know where to keep pages.
	 * op keep {url, html}: the page as Chrome drew it is read and kept (or refused, with why).
	 */
	public static function ext( \WP_REST_Request $r ): \WP_REST_Response {
		$op = (string) $r->get_param( 'op' );
		if ( $op === 'sites' ) {
			return rest_ensure_response( [ 'sites' => self::sites() ] );
		}
		if ( $op !== 'keep' ) {
			return new \WP_REST_Response( [ 'message' => 'Unknown library request.' ], 400 );
		}
		$out = self::keep( (string) $r->get_param( 'url' ), (string) $r->get_param( 'html' ), get_current_user_id() );
		return new \WP_REST_Response( $out, ! empty( $out['refused'] ) ? 422 : 200 );
	}

	/**
	 * Keep one page. @return array{kept?:string, id?:int, title?:string, updated_on?:string, refused?:bool, message:string}
	 * kept: new | changed | same.
	 */
	public static function keep( string $url, string $html, int $user = 0 ): array {
		$site = self::site_for( $url );
		if ( $site === '' ) {
			return [ 'refused' => true, 'message' => 'That page isn’t on one of the store’s reference sites.' ];
		}
		if ( $html === '' || strlen( $html ) > LiveMapper::MAX_BYTES || stripos( $html, '<html' ) === false ) {
			return [ 'refused' => true, 'message' => 'The page was empty or too large to keep.' ];
		}
		if ( PageStore::is_wall( $html ) ) {
			return [ 'refused' => true, 'message' => 'That is the site’s bot check, not its page. Nothing was kept.' ];
		}
		$page = self::read( $html, $url );
		if ( mb_strlen( $page['text'] ) < self::MIN_TEXT ) {
			return [ 'refused' => true, 'message' => 'The page has too little text to keep (a search or list page).' ];
		}
		self::maybe_install();
		global $wpdb;
		$t     = self::table();
		$key   = self::key_url( $url, $page['canonical'], $site );
		$hash  = md5( $key );
		$thash = md5( $page['text'] );
		$now   = current_time( 'mysql', true );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, text_hash, title, updated_on, text, kept_at FROM {$t} WHERE url_hash = %s", $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
		$data  = [
			'url'        => mb_substr( $key, 0, 1000 ),
			'host'       => mb_substr( strtolower( (string) wp_parse_url( $key, PHP_URL_HOST ) ), 0, 190 ),
			'site'       => mb_substr( $site, 0, 255 ),
			'title'      => mb_substr( $page['title'], 0, 500 ),
			'summary'    => $page['summary'],
			'updated_on' => $page['updated_on'] ?: null,
			'fields'     => $page['fields'] ? wp_json_encode( $page['fields'] ) : null,
			'text'       => $page['text'],
			'text_hash'  => $thash,
			'html_gz'    => gzcompress( $html, 6 ),
			'kept_at'    => $now,
			'user_id'    => $user,
		];
		if ( ! $row ) {
			$wpdb->insert( $t, $data + [ 'url_hash' => $hash, 'first_seen' => $now, 'changed_at' => $now, 'kept_count' => 1, 'versions' => 0 ] );
			$id   = (int) $wpdb->insert_id;
			$kept = 'new';
		} elseif ( $row['text_hash'] === $thash ) {
			$id = (int) $row['id'];
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET kept_at = %s, kept_count = kept_count + 1, user_id = %d WHERE id = %d", $now, $user, $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
			$kept = 'same';
		} else {
			$id = (int) $row['id'];
			$wpdb->insert( self::versions_table(), [ 'page_id' => $id, 'title' => (string) $row['title'], 'updated_on' => $row['updated_on'], 'text' => (string) $row['text'], 'text_hash' => (string) $row['text_hash'], 'kept_at' => (string) $row['kept_at'], 'replaced_at' => $now ] );
			$wpdb->update( $t, $data + [ 'changed_at' => $now ], [ 'id' => $id ] );
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET kept_count = kept_count + 1, versions = versions + 1 WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
			$kept = 'changed';
		}
		if ( ! $id ) {
			return [ 'refused' => true, 'message' => 'The page couldn’t be saved. Try again.' ];
		}
		if ( $kept !== 'same' ) {
			Module::info( 'Reference page kept from the Chrome extension', [ 'url' => $key, 'kept' => $kept, 'chars' => mb_strlen( $page['text'] ) ] );
		}
		$said = [ 'new' => 'Kept in the reference library.', 'changed' => 'Kept: the page changed since last time, and the old text is kept too.', 'same' => 'Already kept; unchanged.' ][ $kept ];
		return [ 'kept' => $kept, 'id' => $id, 'title' => $page['title'], 'updated_on' => (string) $page['updated_on'], 'message' => $said ];
	}

	/* ── Reading a page ─────────────────────────────────────────────────── */

	/**
	 * What a page says: title, summary, canonical address, its own "last updated" date, labelled fields from its
	 * opening lines ("Products affected : …"), and its main text as lines.
	 *
	 * @return array{title:string, summary:string, canonical:string, updated_on:string, fields:array<string,string>, text:string}
	 */
	public static function read( string $html, string $url ): array {
		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$xp = new \DOMXPath( $doc );

		$meta = static function ( string $attr, string $name ) use ( $xp ): string {
			$n = $xp->query( sprintf( '//meta[translate(@%s,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="%s"]/@content', $attr, $name ) );
			return $n && $n->length ? trim( (string) $n->item( 0 )->nodeValue ) : '';
		};
		$first = static function ( string $q ) use ( $xp ): string {
			$n = $xp->query( $q );
			return $n && $n->length ? trim( (string) preg_replace( '/\s+/u', ' ', (string) $n->item( 0 )->textContent ) ) : '';
		};

		$title = $meta( 'property', 'og:title' ) ?: $first( '//title' ) ?: $first( '//h1' );
		$summary = $meta( 'name', 'description' ) ?: $meta( 'property', 'og:description' );
		$canon = '';
		$c     = $xp->query( '//link[translate(@rel,"CANONICAL","canonical")="canonical"]/@href' );
		if ( $c && $c->length ) {
			$canon = (string) \WP_Http::make_absolute_url( trim( (string) $c->item( 0 )->nodeValue ), $url );
		}

		// Dates the page states: structured data first, then what the page shows, then its meta tags.
		$ld_mod = '';
		$ld_pub = '';
		foreach ( $xp->query( '//script[@type="application/ld+json"]' ) ?: [] as $s ) {
			$j = json_decode( (string) $s->textContent, true );
			if ( is_array( $j ) ) {
				array_walk_recursive(
					$j,
					static function ( $v, $k ) use ( &$ld_mod, &$ld_pub ) {
						if ( $k === 'dateModified' && $ld_mod === '' && is_string( $v ) ) {
							$ld_mod = $v;
						} elseif ( $k === 'datePublished' && $ld_pub === '' && is_string( $v ) ) {
							$ld_pub = $v;
						}
					}
				);
			}
		}

		// The main text: page furniture dropped, then <main> / <article> / role=main, else the body.
		foreach ( self::DROP_TAGS as $tag ) {
			foreach ( iterator_to_array( $doc->getElementsByTagName( $tag ) ) as $n ) {
				if ( $n->parentNode ) {
					$n->parentNode->removeChild( $n );
				}
			}
		}
		foreach ( iterator_to_array( $xp->query( '//*[@role="navigation" or @role="banner" or @role="contentinfo" or @aria-hidden="true" or @hidden]' ) ?: [] ) as $n ) {
			if ( $n->parentNode ) {
				$n->parentNode->removeChild( $n );
			}
		}
		$root = null;
		foreach ( [ '//main', '//*[@role="main"]', '//article', '//body' ] as $q ) {
			$n = $xp->query( $q );
			if ( $n && $n->length && mb_strlen( trim( (string) $n->item( 0 )->textContent ) ) >= self::MIN_TEXT ) {
				$root = $n->item( 0 );
				break;
			}
		}
		$text = $root ? self::text_of( $root ) : '';
		$text = mb_substr( $text, 0, self::MAX_TEXT );

		$shown = '';
		if ( preg_match( '/\b(?:last\s+(?:updated|modified|reviewed|revised)|updated(?:\s+on)?|modified(?:\s+on)?|revised)\s*(?:on)?\s*[:\-–]?\s*((?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{1,2},?\s+\d{4}|\d{1,2}\s+(?:jan|feb|mar|apr|may|jun|jul|aug|sep|oct|nov|dec)[a-z]*\.?\s+\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\/\d{1,2}\/\d{4})/i', mb_substr( $text, 0, 6000 ), $m ) ) {
			$shown = $m[1];
		}
		$updated = '';
		foreach ( [ $ld_mod, $shown, $meta( 'property', 'article:modified_time' ), $meta( 'property', 'og:updated_time' ), $meta( 'name', 'last-modified' ), $meta( 'name', 'dcterms.modified' ), $meta( 'name', 'moddate' ), $ld_pub, $meta( 'property', 'article:published_time' ) ] as $d ) {
			$ts = $d !== '' ? strtotime( $d ) : false;
			if ( $ts && $ts > 315532800 && $ts < time() + DAY_IN_SECONDS ) { // after 1980, not in the future
				$updated = gmdate( 'Y-m-d', $ts );
				break;
			}
		}

		return [
			'title'      => mb_substr( $title, 0, 500 ),
			'summary'    => mb_substr( $summary, 0, 2000 ),
			'canonical'  => $canon,
			'updated_on' => $updated,
			'fields'     => self::fields_of( $text ),
			'text'       => $text,
		];
	}

	/** An element's text as lines: one per block, list items marked, table cells separated, spaces collapsed. */
	private static function text_of( \DOMNode $root ): string {
		$buf  = '';
		$walk = static function ( \DOMNode $n ) use ( &$walk, &$buf ): void {
			foreach ( $n->childNodes as $c ) {
				if ( $c instanceof \DOMText ) {
					$buf .= $c->nodeValue;
					continue;
				}
				if ( ! $c instanceof \DOMElement ) {
					continue;
				}
				$tag   = strtolower( $c->tagName );
				$block = in_array( $tag, self::BLOCK_TAGS, true );
				if ( $block ) {
					$buf .= "\n";
				}
				if ( $tag === 'li' ) {
					$buf .= '- ';
				} elseif ( $tag === 'td' || $tag === 'th' ) {
					$buf .= ' | ';
				} elseif ( preg_match( '/^h[1-6]$/', $tag ) ) {
					$buf .= "\n";
				}
				if ( $tag === 'img' && $c->getAttribute( 'alt' ) !== '' ) {
					$buf .= ' ' . $c->getAttribute( 'alt' ) . ' ';
				}
				$walk( $c );
				if ( $block ) {
					$buf .= "\n";
				}
			}
		};
		$walk( $root );
		$lines = [];
		foreach ( preg_split( '/\n/u', html_entity_decode( $buf, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) as $l ) {
			$l = trim( (string) preg_replace( '/[ \t\x{00A0}\x{200B}]+/u', ' ', $l ) );
			$l = (string) preg_replace( '/^\|\s*/', '', $l );
			if ( $l === '' ) {
				if ( $lines && end( $lines ) !== '' ) {
					$lines[] = '';
				}
				continue;
			}
			$lines[] = $l;
		}
		return trim( implode( "\n", $lines ) );
	}

	/** "Label : value" lines among the opening lines (short labels only), as label => value. */
	private static function fields_of( string $text ): array {
		$out = [];
		foreach ( array_slice( explode( "\n", $text ), 0, self::FIELD_LINES ) as $l ) {
			if ( preg_match( '/^([A-Za-z][A-Za-z ()\/&-]{1,40}?)\s*:\s+(\S.{0,300})$/u', $l, $m ) && str_word_count( $m[1] ) <= 4 && ! preg_match( '#^https?$#i', $m[1] ) ) {
				$label = ucfirst( strtolower( trim( $m[1] ) ) );
				if ( ! isset( $out[ $label ] ) ) {
					$out[ $label ] = trim( $m[2] );
				}
			}
		}
		return array_slice( $out, 0, 20, true );
	}

	/* ── Searching ──────────────────────────────────────────────────────── */

	/**
	 * Kept pages, newest first, or the best matches for $q (full-text; words shorter than the database's minimum fall
	 * back to a plain contains search). @return array{total:int, rows:array}
	 */
	public static function search( string $q = '', string $site = '', int $limit = 50, int $offset = 0, bool $boolean = false ): array {
		self::maybe_install();
		global $wpdb;
		$t      = self::table();
		$limit  = max( 1, min( self::LIST_MAX, $limit ) );
		$where  = '1=1';
		$args   = [];
		if ( $site !== '' ) {
			$where .= ' AND site = %s';
			$args[] = $site;
		}
		$q     = trim( $q );
		$score = '0';
		$order = 'kept_at DESC';
		if ( $q !== '' ) {
			$mode   = $boolean ? 'IN BOOLEAN MODE' : 'IN NATURAL LANGUAGE MODE';
			$hits   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where} AND MATCH(title, text) AGAINST (%s {$mode})", array_merge( $args, [ $q ] ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name and fixed mode
			if ( $hits > 0 ) {
				$score  = $wpdb->prepare( "MATCH(title, text) AGAINST (%s {$mode})", $q ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed mode
				$where .= " AND {$score} > 0";
				$order  = 'score DESC, kept_at DESC';
			} else {
				$like   = '%' . $wpdb->esc_like( $q ) . '%';
				$where .= ' AND (title LIKE %s OR text LIKE %s)';
				$args[] = $like;
				$args[] = $like;
			}
		}
		$count = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$where}", $args ) : "SELECT COUNT(*) FROM {$t} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- built above from placeholders
		$sql   = "SELECT id, url, site, title, summary, updated_on, fields, kept_at, changed_at, first_seen, kept_count, versions, {$score} AS score, " . ( $q !== '' ? 'text' : "'' AS text" ) . " FROM {$t} WHERE {$where} ORDER BY {$order} LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, [ $limit, max( 0, $offset ) ] ) ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built above from placeholders
		foreach ( $rows as &$r ) {
			$r['snippet'] = $q !== '' ? self::snippet( (string) $r['text'], $q ) : '';
			unset( $r['text'] );
			$r['fields'] = $r['fields'] ? (array) json_decode( (string) $r['fields'], true ) : [];
			$r['id']     = (int) $r['id'];
			$r['score']  = round( (float) $r['score'], 3 );
		}
		unset( $r );
		return [ 'total' => $count, 'rows' => $rows ];
	}

	/** The text around the first place one of $q's words appears. */
	private static function snippet( string $text, string $q ): string {
		$at = false;
		foreach ( preg_split( '/[\s"+\-*~()<>]+/u', $q ) as $w ) {
			if ( mb_strlen( $w ) >= 2 ) {
				$p = mb_stripos( $text, $w );
				if ( $p !== false && ( $at === false || $p < $at ) ) {
					$at = $p;
				}
			}
		}
		if ( $at === false ) {
			return mb_substr( preg_replace( '/\s+/u', ' ', $text ), 0, self::SNIPPET * 2 );
		}
		$from = max( 0, $at - self::SNIPPET );
		$s    = (string) preg_replace( '/\s+/u', ' ', mb_substr( $text, $from, self::SNIPPET * 2 ) );
		return ( $from > 0 ? '…' : '' ) . trim( $s ) . '…';
	}

	/** One kept page with its text and earlier versions (newest first), or null. */
	public static function get( int $id, bool $with_versions = true ): ?array {
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, url, host, site, title, summary, updated_on, fields, text, first_seen, kept_at, changed_at, kept_count, versions FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
		if ( ! $row ) {
			return null;
		}
		$row['id']     = (int) $row['id'];
		$row['fields'] = $row['fields'] ? (array) json_decode( (string) $row['fields'], true ) : [];
		if ( $with_versions ) {
			$v                    = self::versions_table();
			$row['earlier'] = $wpdb->get_results( $wpdb->prepare( "SELECT id, title, updated_on, kept_at, replaced_at, CHAR_LENGTH(text) AS chars FROM {$v} WHERE page_id = %d ORDER BY replaced_at DESC", $id ), ARRAY_A ) ?: []; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
		}
		return $row;
	}

	/** A kept page by its address (as kept, or as opened). */
	public static function id_for_url( string $url ): int {
		self::maybe_install();
		global $wpdb;
		$t    = self::table();
		$site = self::site_for( $url );
		$key  = $site !== '' ? self::key_url( $url, '', $site ) : $url;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE url_hash = %s OR url = %s LIMIT 1", md5( $key ), $url ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
	}

	/** An earlier version's text. */
	public static function version_text( int $version_id ): string {
		global $wpdb;
		$v = self::versions_table();
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT text FROM {$v} WHERE id = %d", $version_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
	}

	/** Pages kept per site: site => { pages, last }. */
	public static function counts(): array {
		self::maybe_install();
		global $wpdb;
		$t   = self::table();
		$out = [];
		foreach ( $wpdb->get_results( "SELECT site, COUNT(*) AS pages, MAX(kept_at) AS last FROM {$t} GROUP BY site", ARRAY_A ) ?: [] as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name
			$out[ (string) $r['site'] ] = [ 'pages' => (int) $r['pages'], 'last' => (string) $r['last'] ];
		}
		return $out;
	}

	/** Forget a kept page and its earlier versions. */
	public static function delete( int $id ): bool {
		global $wpdb;
		$row = self::get( $id, false );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( self::versions_table(), [ 'page_id' => $id ] );
		$wpdb->delete( self::table(), [ 'id' => $id ] );
		if ( class_exists( 'MMI_Audit_Log' ) ) {
			\MMI_Audit_Log::record( 'mmi-data-pipeline', 'library.delete', [ 'object_type' => 'library', 'object_id' => (string) $id, 'details' => [ 'url' => $row['url'], 'title' => $row['title'] ] ] );
		}
		return true;
	}

	/* ── Admin REST ─────────────────────────────────────────────────────── */

	public static function rest_list( \WP_REST_Request $r ): \WP_REST_Response {
		$res = self::search( (string) $r->get_param( 'q' ), (string) $r->get_param( 'site' ), (int) ( $r->get_param( 'limit' ) ?: 100 ), (int) $r->get_param( 'offset' ) );
		$cnt = self::counts();
		$res['sites'] = array_map(
			static fn( $s ) => [ 'site' => $s, 'pages' => $cnt[ $s ]['pages'] ?? 0, 'last' => $cnt[ $s ]['last'] ?? '' ],
			self::sites()
		);
		$res['other'] = array_sum( array_map( static fn( $c ) => $c['pages'], array_diff_key( $cnt, array_flip( self::sites() ) ) ) ); // kept under a site since removed
		return rest_ensure_response( $res );
	}

	public static function rest_page( \WP_REST_Request $r ): \WP_REST_Response {
		$row = self::get( (int) $r->get_param( 'id' ) );
		return $row ? rest_ensure_response( $row ) : new \WP_REST_Response( [ 'message' => 'That page isn’t in the library.' ], 404 );
	}

	public static function rest_sites( \WP_REST_Request $r ): \WP_REST_Response {
		$in = $r->get_param( 'sites' );
		if ( ! is_array( $in ) ) {
			return new \WP_REST_Response( [ 'message' => 'No list of sites was sent.' ], 400 );
		}
		$bad = array_values( array_filter( array_map( 'strval', $in ), static fn( $s ) => trim( $s ) !== '' && self::normalize_site( $s ) === '' ) );
		if ( $bad ) {
			return new \WP_REST_Response( [ 'message' => sprintf( '“%s” isn’t a site address (or is this store’s own). Type it like help.example.com or help.example.com/articles/.', $bad[0] ) ], 400 );
		}
		return rest_ensure_response( [ 'sites' => self::save_sites( $in ) ] );
	}

	public static function rest_delete( \WP_REST_Request $r ): \WP_REST_Response {
		return self::delete( (int) $r->get_param( 'id' ) ) ? rest_ensure_response( [ 'deleted' => true ] ) : new \WP_REST_Response( [ 'message' => 'That page isn’t in the library.' ], 404 );
	}

	/* ── The section on the Chrome extension page ───────────────────────── */

	public static function assets( $hook ): void {
		if ( ! is_string( $hook ) || strpos( $hook, ExtBridge::SLUG ) === false ) {
			return;
		}
		$rel = 'js/library.js';
		$f   = dirname( __DIR__ ) . '/assets/' . $rel;
		wp_enqueue_script( 'mmpt-library', PageEvents::asset_url( $rel ), [], file_exists( $f ) ? (string) filemtime( $f ) : '1', true );
		wp_localize_script( 'mmpt-library', 'mmptLibrary', [ 'rest' => esc_url_raw( rest_url( Rest::NS . '/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
	}

	public static function render_section(): void {
		?>
		<div class="mmi-process-section" id="mmpt-library">
			<div class="mmi-section-header">
				<div>
					<h3 class="mmi-process-section-header"><span class="dashicons dashicons-book"></span> <?php esc_html_e( 'Reference library', 'mmi-data-pipeline' ); ?></h3>
					<p class="mmi-process-section-description"><?php esc_html_e( 'Help and knowledge-base pages you open on these sites are kept here as you read them, with their text and the date the page says it was last updated, so they can be searched later. Open a page again after it changes and the old text is kept too. Nothing is fetched on its own: only pages someone opened in a connected Chrome are kept.', 'mmi-data-pipeline' ); ?></p>
				</div>
				<button type="button" class="button button-primary" id="mmpt-lib-add-open"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add a site', 'mmi-data-pipeline' ); ?></button>
			</div>
			<div class="mmi-section-content">
				<form id="mmpt-lib-add" class="mmpt-lib-add" hidden>
					<label for="mmpt-lib-site"><?php esc_html_e( 'Site', 'mmi-data-pipeline' ); ?></label>
					<input type="text" id="mmpt-lib-site" class="regular-text" placeholder="help.example.com/articles/" autocomplete="off">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Add', 'mmi-data-pipeline' ); ?></button>
					<button type="button" class="button" id="mmpt-lib-add-cancel"><?php esc_html_e( 'Cancel', 'mmi-data-pipeline' ); ?></button>
					<p class="description"><?php esc_html_e( 'A host, or a host with the start of a path to keep only part of the site (its articles, not its search pages).', 'mmi-data-pipeline' ); ?></p>
				</form>
				<div id="mmpt-lib-said" class="notice inline" role="status" hidden><p></p></div>
				<div class="mmi-table-scroll-wrapper">
					<table class="widefat striped mmpt-lib-sites">
						<thead><tr><th><?php esc_html_e( 'Site', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Pages kept', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Last kept', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'This browser', 'mmi-data-pipeline' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mmi-data-pipeline' ); ?></span></th></tr></thead>
						<tbody id="mmpt-lib-sites"><tr><td colspan="5"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading…', 'mmi-data-pipeline' ); ?></td></tr></tbody>
					</table>
				</div>
				<div class="mmpt-lib-search">
					<label class="screen-reader-text" for="mmpt-lib-q"><?php esc_html_e( 'Search kept pages', 'mmi-data-pipeline' ); ?></label>
					<input type="search" id="mmpt-lib-q" class="regular-text" placeholder="<?php esc_attr_e( 'Search kept pages', 'mmi-data-pipeline' ); ?>" autocomplete="off">
					<label class="screen-reader-text" for="mmpt-lib-filter"><?php esc_html_e( 'Site', 'mmi-data-pipeline' ); ?></label>
					<select id="mmpt-lib-filter"><option value=""><?php esc_html_e( 'Every site', 'mmi-data-pipeline' ); ?></option></select>
					<span id="mmpt-lib-total" class="description"></span>
				</div>
				<div class="mmi-table-scroll-wrapper">
					<table class="widefat striped mmpt-lib-pages">
						<thead><tr><th><?php esc_html_e( 'Page', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Site', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Page updated', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Kept', 'mmi-data-pipeline' ); ?></th><th><?php esc_html_e( 'Versions', 'mmi-data-pipeline' ); ?></th><th><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'mmi-data-pipeline' ); ?></span></th></tr></thead>
						<tbody id="mmpt-lib-pages"><tr><td colspan="6"><span class="spinner is-active"></span> <?php esc_html_e( 'Loading…', 'mmi-data-pipeline' ); ?></td></tr></tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}
}
