<?php
/**
 * SoundCloud tracks and sets for a product's audio demos (owner 2026-11-02: "We should try to include SoundCloud
 * links/data, too. SoundCloud has an API, I think, too, which should be investigated").
 *
 * Only SoundCloud's oEmbed endpoint is used (soundcloud.com/oembed: public, no key, no account). It names a track or
 * set (title, author, artwork) and gives its player, whose address carries the api.soundcloud.com id, so a page
 * address ("soundcloud.com/soundiron/sets/…") and a player's ("api.soundcloud.com/playlists/1556852515") count as one
 * item. The API proper needs an app registered from a paid Artist Pro subscription, so it is not used (spend rule).
 * Answers are kept OEMBED_DAYS (a miss a day), and calls are paced through MMI_API_Throttler.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SoundCloud {

	const OEMBED      = 'https://soundcloud.com/oembed';
	const OEMBED_DAYS = 30;
	const THROTTLE    = 'soundcloud_oembed';
	const GAP_MS      = 600;
	/** A track or set: the player's api address, or a page address that isn't a profile's list. */
	const API_RE  = '~^https?://api\.soundcloud\.com/(tracks|playlists)/(?:soundcloud:(?:tracks|playlists):)?(\d+)~i';
	const PAGE_RE = '~^https?://(?:www\.|m\.)?soundcloud\.com/[^/?#]+/[^?#]+~i';
	const LISTS   = '~/(?:followers|following|likes|reposts|tracks|albums|popular-tracks|sets)/?$~';

	/** A SoundCloud track or set address (api or page), or '' for anything else. */
	public static function url( string $url ): string {
		$url = trim( $url );
		if ( preg_match( '~^https?://w\.soundcloud\.com/player/?\?~i', $url ) ) {
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
			$url = (string) ( $q['url'] ?? '' );
		}
		if ( preg_match( self::API_RE, $url, $m ) ) {
			return 'https://api.soundcloud.com/' . strtolower( $m[1] ) . '/' . $m[2];
		}
		if ( preg_match( self::PAGE_RE, $url, $m ) && ! preg_match( self::LISTS, $m[0] ) ) {
			return (string) strtok( $m[0], '?#' );
		}
		return '';
	}

	/** One item's key: "playlists:1556852515" (from its address or its oEmbed player), else its page address. */
	public static function key( string $url ): string {
		$u = self::url( $url );
		if ( $u === '' ) {
			return '';
		}
		if ( preg_match( self::API_RE, $u, $m ) ) {
			return strtolower( $m[1] ) . ':' . $m[2];
		}
		$o = self::oembed( $u );
		return $o && $o['key'] !== '' ? $o['key'] : strtolower( preg_replace( '~^https?://(?:www\.|m\.)?~i', '', $u ) );
	}

	/**
	 * What SoundCloud says of a track or set: { key, title, author, thumb }, or null (not found, or not answered).
	 * @return array{key:string,title:string,author:string,thumb:string}|null
	 */
	public static function oembed( string $url ): ?array {
		$u = self::url( $url );
		if ( $u === '' ) {
			return null;
		}
		$tk  = 'mmpt_sc_' . md5( $u );
		$got = get_transient( $tk );
		if ( is_array( $got ) ) {
			return $got['key'] !== '' || $got['title'] !== '' ? $got : null;
		}
		\MMI_API_Throttler::throttle( self::THROTTLE, [ 'min_gap_ms' => self::GAP_MS ] );
		$res  = wp_safe_remote_get( add_query_arg( [ 'format' => 'json', 'url' => rawurlencode( $u ) ], self::OEMBED ), [ 'timeout' => 10 ] );
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( $code === 429 ) {
			\MMI_API_Throttler::penalize( self::THROTTLE, (int) wp_remote_retrieve_header( $res, 'retry-after' ) );
		}
		$j   = $code === 200 ? json_decode( (string) wp_remote_retrieve_body( $res ), true ) : null;
		$out = [ 'key' => '', 'title' => '', 'author' => '', 'thumb' => '' ];
		if ( is_array( $j ) ) {
			if ( preg_match( '~src="([^"]+)"~', (string) ( $j['html'] ?? '' ), $m ) ) {
				parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $q );
				if ( preg_match( self::API_RE, (string) ( $q['url'] ?? '' ), $a ) ) {
					$out['key'] = strtolower( $a[1] ) . ':' . $a[2];
				}
			}
			$out['title']  = sanitize_text_field( (string) ( $j['title'] ?? '' ) );
			$out['author'] = sanitize_text_field( (string) ( $j['author_name'] ?? '' ) );
			$out['thumb']  = esc_url_raw( (string) ( $j['thumbnail_url'] ?? '' ) );
		}
		$known = $out['key'] !== '' || $out['title'] !== '';
		set_transient( $tk, $out, $known || $code === 404 ? self::OEMBED_DAYS * DAY_IN_SECONDS : DAY_IN_SECONDS );
		return $known ? $out : null;
	}
}
