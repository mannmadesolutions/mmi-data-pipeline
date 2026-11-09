<?php
/**
 * Reads a vendor's product page into a clean description and the product's
 * own images (Product Content handoff §4, owner 2026-10-05: "the best data is
 * gotten by scraping the product page").
 *
 * Best signal first: schema.org Product data (JSON-LD), Open Graph, then the
 * page's main description container. Shop chrome (financing, shipping,
 * coupons, cookies, newsletters, cart and review widgets) is removed, and
 * what was removed is returned so the reviewer can see it. Text is kept
 * verbatim; nothing is generated.
 *
 * Pure: takes HTML, returns data. Fetching, politeness and storage stay in
 * VendorFetcher.
 *
 * @package MannMade\DataPipeline\ProductData
 */

namespace MannMade\DataPipeline\ProductData;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class VendorPage {

	const MIN_TEXT       = 200;   // the same "thin" line as Enrichment::THIN_CHARS
	const MAX_TEXT       = 4000;  // a product description, not the whole page
	const MAX_BLOCKS     = 30;
	const LOW_RES_EDGE   = 300;   // Image Trim's min_eff: smaller pictures are kept but marked low_res for the reviewer (owner 2026-10-05)
	const FOCUS          = 0.8;   // a candidate container must hold this share of the best cleaned copy
	const WALK_LIMIT     = 600;   // raw blocks read per candidate before cleaning (menus come first on some pages)

	/** Containers that hold a product's description, most specific first (class/id/itemprop fragments). */
	const CONTAINER_HINTS = [
		'itemprop="description"', 'product-description', 'product__description', 'product_description', 'productdescription',
		'product-single__description', 'woocommerce-tabs-panel--description', 'tab-description', 'description-content',
		'product-details', 'product-detail', 'product-info', 'product-overview', 'overview', 'description', 'rte',
	];

	/** Headings that start the part of the page that isn't the description; reading stops there. */
	/** A list item that is a link to the vendor's social profile ("Facebook", "Mogami Twitter"). */
	/** A pager's items (owner 2026-11-04: a category page's "You're currently reading page 1, Page 2, Page 3, Page Next" became a product's features). */
	const PAGER_ITEM    = '/^(?:you.{0,6}re currently reading page \d+|page \d+|page (?:next|previous|prev)|next(?: page)?|prev(?:ious)?(?: page)?|first|last|\d{1,3}|[«»‹›<>]+)$/iu';
	/** Body classes of a shop's category or archive page: Magento, WooCommerce, Shopify. */
	const LISTING_BODY  = '/<body\b[^>]*\bclass=["\'][^"\']*\b(?:catalog-category-view|tax-product_cat|post-type-archive-product|template-collection)\b/i';
	const SOCIAL_ITEM   = '/^(?:[\p{L}\d&.\'-]+\s+)?(?:facebook|twitter|x|youtube|instagram|linkedin|tiktok|pinterest|soundcloud|discord|vimeo|threads|bluesky|spotify|twitch|reddit)$/iu';
	const STOP_HEADINGS = '/^(related|you may also|customers (also|who)|recommended|similar products|more from|reviews?|customer reviews|write a review|faq|frequently asked|compare|bundles? (that|containing)|recently viewed|newsletter|sign up|join (our|the)|follow us|stay (in touch|connected))\b/i';

	/** Shop chrome, not product copy. A block matching any of these is removed (and reported). */
	const BOILERPLATE = [
		'/\b(klarna|affirm|afterpay|sezzle|paypal pay later|pay (over time|in \d+)|interest[- ]free|installments?|financing|flexible payment)\b/i',
		'/\b(free shipping|shipping (is|costs?|calculated|policy)|ships? (in|within|free)|delivery (time|within)|returns? policy|money[- ]back|\d+[- ]day (return|guarantee))\b/i',
		'/\b(coupon|promo(tion)? code|discount code|voucher|use code|enter (the following )?code|checkout|add to (cart|basket|wish ?list)|in stock|out of stock|pre-?order now|buy now|price match)\b/i',
		'/\b(cookies?|privacy policy|terms (of|and) (service|use)|gdpr|consent|personali[sz]e (our|this) (site|website))\b/i',
		'/\b(newsletter|subscribe (to|for)|sign up (for|to)|join our (mailing|email) list|follow us|share (this|on))\b/i',
		'/\b(write a review|be the first to review|\d+(\.\d)? (out of 5|stars?)|customer reviews?|rated \d)\b/i',
		'/\b(log ?in|sign in|create (an )?account|don.?t have an account|my account|already (own|have) (it|an account))\b/i',
		'/\b(go ahead an?d? start shopping)\b/i',
		'/\b(get (a |the )?(free )?trial|free trial|try (it )?for free|audio demos?|user manual|download (the )?(pdf|trial|demo|manual)|check compatibility|system requirements|authori[sz]e|licensing system|overview resources|watch (the )?video)\b/i',
		'/^\s*(from:?\s*|price:?\s*|only\s*)?[$€£]\s?\d[\d,.]*\s*(usd|eur)?\s*$/i',
		'/^\s*(order as|sku|part (no|number)|item #?)\s*:?\s*$/i',
		'/\b(click here|print this page|we need your consent|third[- ]party service|accept the service|not permitted to load|trackers that are not disclosed|enable javascript|your browser (does not|doesn.t) support)\b/i',
		// Store price lines (2026-10-08: "Prices in EUR incl. 19% VAT", "Sale price$6.99 Regular price$35.00", "Save $50").
		'/\b((incl|excl)\.?|including|excluding|plus) (\d+(\.\d+)?\s?% )?(vat|sales tax|tax)\b|\b(sales tax may apply|sale price|regular price|displayed price|sold out|save [$€£]\s?\d|add to wish ?list|payment plan|monthly payments?)\b/i',
		// The vendor's own offers and app stores: we sell neither (2026-10-14, Overloud: "extend the license for iOS at just $1", "Get it in the App Store").
		'/\b(app store|google play|play store|intro(ductory)? (promo|price|offer)|(at|for) (just|only) [$€£]\s?\d|only [$€£]\s?\d|unlock all \d+\+?)/i', // + Antelope's "Unlock all 75+ Antelope Native Plugins by joining Antelope Cosmos." (2026-10-27)
	];

	/**
	 * Calls to action: buttons and links to the vendor's other pages, never product copy. Short blocks
	 * only (CTA_MAX). Owner 2026-10-14 (Overloud Wild Collection): "SEE PRODUCT DETAILS...",
	 * "LEARN MORE...", "WATCH THIS TUTORIAL FOR A STEP-BY-STEP GUIDE ON THE RIG PLAYER".
	 */
	const CTA      = '/^(learn more|read more|see more|see details|see (all|the full|full|product) (details|specs|features|list)|see (the )?full [\w -]{0,20}(list|specs|details)|more (info|information|details)|find out more|discover more|view (details|more|all)|show more|click here|tap here|watch (this|the|our) (tutorial|video|walkthrough|demo|overview)|check out (all|our|the full|more)|get it (on|in|from) the|download (it )?on the|(buy|shop|order) (it )?now)\b[^,!?]{0,50}$/i';
	const CTA_TAIL = '/(\.\.\.|…|»|›|→|>>)\s*$/u'; // "Watch demos...", "See it in action…": a button label, when very short
	const CTA_VERB = '/^(learn|read|see|find|discover|explore|view|show|watch|check|click|tap|shop|browse|listen|hear|visit|go|get|download|try|buy|order|compare|meet|join|more)\b/i';
	const CTA_MAX  = 90;

	/**
	 * Headings that start a part about the vendor's own store, delivery or other products rather than
	 * this product: how to install or authorize, "Also on iOS", "Check out all the rig libraries",
	 * tutorials. The part is left out up to the next heading (or capitals line that reads as one).
	 */
	const VENDOR_SECTIONS = '/^(how to (install|authori[sz]e|activate|register|download|buy|order|upgrade|redeem)\b|install(ation|ing)?( (instructions|guide|steps|notes))?$|getting started$|also (on|for|available (on|for)) (ios|ipad|iphone|android|mobile)\b|(check out|see|browse|explore|discover|shop) (all|more|our|other)\b[^,.!?]*$|(video )?tutorials?$|watch (the|this|our) (tutorial|video)|testimonials?$|(user|artist|customer) (reviews|quotes|testimonials)$|what (our )?(users|customers|artists|people|producers|engineers|composers|musicians|the pros) (are )?say(ing)?\b|what they(\'re| are)? say(ing)?\b|praise for\b|(let )?(our )?(customers|users|artists) speak\b|artist(\'s|s\'|s)? opinions?$|press( kit| resources| releases?)?$|[\w -]{0,30}goodies$)/i';

	/**
	 * The vendor speaking as itself: "we", "our", "us" (never "US"), and a person's "I'm", "my", "I love…" (testimonials,
	 * FAQ questions). Product data never speaks as the maker (owner 2026-10-21). A bare "I" is a Roman numeral ("Volume I").
	 */
	const VENDOR_VOICE = '/\b(?:[Ww]e|WE|[Oo]urs?|OURS?|[Oo]urselves|[Ww]e(?:\'|’)(?:re|ve|ll|d)|[Uu]s|[Mm]y|[Mm]e)\b|\bI(?:\'|’)(?:m|ve|d|ll)\b|\bI\s+(?:am|was|have|had|use|used|love|loved|think|thought|found|find|tried|turned|can|could|would|will|really|just|recently|bought|got|highly|ever|never|always|do|did|don\'t|don’t|need|needed|wanted|want|recommend|own|started|personally)\b/u';

	/** A sentence that sends the reader somewhere or sells something: "Click here", "Contact us", "free trial", "sign up". Judged on short sentences only. */
	const VENDOR_ASK = '/\b(for the price of \w+|discounted from|click (?:here|below|above|on (?:the )?(?:link|button))|click the (?:link|button)|tap here|visit (?:our|the) (?:website|site|store|shop|page)|contact (?:us|our)|get in touch|sign up|subscribe|join (?:our|the) (?:community|newsletter|mailing list)|follow us|download (?:the |a |your )?(?:free )?(?:trial|demo)|try (?:it )?(?:for )?free|free trial|buy (?:it )?now|order now|add to (?:cart|basket)|scroll (?:down|up)|see (?:below|above)|use (?:the )?(?:code|coupon)|e-?mail us|opens in a new (?:window|tab)|clicking (?:on )?(?:the )?(?:button|link)|hover over (?:the )?(?:dots|image|icons?)|need help|download (?:the )?press kit|view screenshots?|shop now|save (?:up to )?\d+(?=\s?%)|\d+\s?% off|limited[- ]time|sale ends|intro(?:ductory)? (?:price|offer))\b/iu';
	/** A shop's product meta line ("Category: Equalizers, Mastering", "SKU: …"), at any length. */
	const STORE_META    = '/^(?:categor(?:y|ies)|tags?|sku|product code|item (?:no\.?|number))\s*:/iu';
	const CARD_MARK = 'data-mmpt-card="1"'; // on the page SharedPages::card() makes of a card: everything in it is this product's
	const CARD_PICTURES = 2; // a card shows a product, not a gallery: more than this and it isn't really a card
	const VENDOR_ASK_MAX = 160; // longer sentences that merely mention a trial or a store are product copy
	const PRICE         = '/[$€£]\s?\d|^\/\s?(?:mo|month|yr|year)\b|^(?:or|and|0)$/iu'; // the vendor's price (never ours), or a piece of its price widget ("/month", "or"): a short sentence carrying one goes
	/** Glued to the front of a block: a breadcrumb ("HOME > ") or the vendor's prices ("$31.00 $49.00 "). */
	const LEAD_CHROME   = '/^(?:home\s*(?:>|›|»|\/)\s*|(?:[$€£]\s?\d[\d.,]*\s*)+)+/iu';

	/**
	 * Chrome that marks a block even when it is long: a long block is usually prose, so only phrases
	 * no product copy uses (a site menu or a cart widget glued onto the copy, 2026-10-08).
	 */
	const LONG_CHROME = '/\b(klarna|affirm|afterpay|coupon code|enter the following code|cookies?|skip to (main )?content|add to cart|sale price|regular price|add to wish ?list|please remove to continue|open menu|close menu|item added to your cart|continue shopping|open media \d+ in|verified owner|choosing a selection results in a full page refresh|your cart is empty|to continue checking out|all rights reserved|copyright\s?©?\s?\d{4}|is an? (registered )?trademark|(?<!\(a )registered trademarks? (of|property)|trademark property of|all other (product names|trademarks|brand names)|update required to play the media|you will be redirected to|must be logged in to post a review|uses akismet to reduce spam|\d+ total reviews?|[$€£]\d[\d.,]* \/ month for \d+ months)\b/i';

	/** Image URLs that are never product pictures. Size alone never discards a picture (owner 2026-10-05). */
	const IMAGE_SKIP = '/(logo|icon|badge|favicon|sprite|placeholder|spinner|loader|avatar|\bflags?\b|payment|paypal|klarna|trustpilot|rating|star[-_]|facebook|twitter|instagram|youtube|ytimg|vimeocdn|vumbnail|preview_images\/|(?:hq|mq|sd|maxres)default|\.svg(\?|$)|data:)/i'; // preview_images, hqdefault: the poster of a video slide (Shopify, YouTube)

	/** A section of other products by name: the gallery reader's "carousel" and "slider" also take these (owner 2026-11-06, FATSO's "You Might Also Like"). */
	const OTHER_PRODUCTS = '/upsell|cross-?sell|related|recommend|also-?(?:like|bought|viewed)|you-?may|product[-_]?card|similar-?products/i';
	const OTHER_CLIMB    = 10; // elements up from the picture

	/** What makes an element around a picture a click-to-play video (owner 2026-11-06): its picture is the video's poster. */
	const VIDEO_ATTR   = '/^data-(?:video|youtube|yt-|vimeo)/i';
	const VIDEO_ADDR   = '~youtube(?:-nocookie)?\.com/|youtu\.be/|vimeo\.com/|wistia\.|\.mp4(?:[?#]|$)~i';
	const VIDEO_CLIMB  = 4;  // elements up from the picture
	const VIDEO_WORDS  = 60; // characters: past this it is a product's card with a video in it, not a video's own frame

	/**
	 * Per-vendor extraction profiles, for sites whose markup the generic reader misreads. XPath
	 * queries; the first that yields copy wins. Add one when a host's dry-run output is wrong.
	 */
	const PROFILES = [
		// Mogami (owner 2026-10-05): the product's own block is #skur (h2.skutitle + ul.detail),
		// its photo #skul; everything else is a menu in capitals and a site-wide brand paragraph.
		'mogamicable.com' => [
			'description' => [ '//*[@id="skur"]' ],
			'images'      => [ '//*[@id="skul"]//img' ],
		],
	];

	/**
	 * @return array{
	 *   description_html:string, text_chars:int, method:string, removed:string[],
	 *   images:array<int,array{url:string,width:int,height:int,source:string}>,
	 *   name:string, brand:string, identifiers:array<string,string>, flags:string[]
	 * }
	 *
	 * @param string $product_name The store's product title: used to pick the product's own pictures on
	 *                             pages that don't mark them up, and to flag a page about another version.
	 */
	public static function extract( string $html, string $page_url, string $product_name = '' ): array {
		$out  = [ 'kept_lists' => 0, 'blocks' => [], 'description_html' => '', 'text_chars' => 0, 'method' => 'none', 'removed' => [], 'images' => [], 'name' => '', 'product' => $product_name, 'brand' => '', 'identifiers' => [], 'flags' => [] ];
		$ld   = self::json_ld_product( $html );
		$og   = self::open_graph( $html );
		// With a vendor map (confirmed, or the editor's draft in its product preview) the page is read with what a script
		// would draw (script_content()), as the editor shows it: a part may name a drawn gallery (waves). No map: as stored.
		$mapped = VendorMaps::profile( $page_url, $html ) !== null; // the page's type decides, when its site has types (MapTypes)
		$dom    = self::dom( $mapped ? self::script_content( $html ) : $html );
		$base   = $page_url;

		$out['name']        = $ld['name'] !== '' ? $ld['name'] : ( $og['title'] ?? '' );
		if ( $dom && $out['name'] === '' && ( $h1 = $dom->getElementsByTagName( 'h1' )->item( 0 ) ) ) {
			$out['name'] = self::text( $h1->textContent );
		}
		if ( $product_name !== '' && self::other_version( $product_name, $out['name'] ) ) {
			$out['flags'][] = 'page_version_differs'; // e.g. an RX 10 listing whose vendor URL now shows RX 12
		}
		$out['brand']       = $ld['brand'];
		$out['identifiers'] = $ld['identifiers'];

		// Description: the page's own description container when it carries real copy; JSON-LD's
		// description otherwise (often a shortened version); then og/meta description.
		$host      = preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( $page_url, PHP_URL_HOST ) ) );
		$profile   = VendorMaps::profile( $page_url, $html ) ?? ( self::PROFILES[ $host ] ?? null ); // a confirmed vendor map first (owner 2026-10-09)
		// A mapped description chooses the description's text; it never takes away what the generic reader
		// finds beside it: its feature lists are kept (owner 2026-10-10: "this product data process at large
		// should augment the existing data set in a non-destructive manner"; Antelope's paragraph-only
		// description had dropped the feature lists, Features 70 → 0 pages).
		$skip      = MapCheck::text_skip_set( $page_url ); // lines the owner marked "not product copy" on this site (2026-10-14)
		$mapped    = $dom ? self::profile_blocks( $dom, $profile, $skip, $page_url ) : null;
		$container = $dom ? ( $mapped ? self::keep_lists( $mapped, self::description_blocks( $dom, $skip, $page_url ) ) : self::description_blocks( $dom, $skip, $page_url ) ) : [ 'blocks' => [], 'removed' => [] ];
		$out['kept_lists'] = (int) ( $container['kept'] ?? 0 );
		$ld_blocks = $ld['description'] !== '' ? self::clean_blocks( self::html_to_blocks( $ld['description'] ), $skip ) : [ 'blocks' => [], 'removed' => [] ];
		$c_len     = self::blocks_len( $container['blocks'] );
		$l_len     = self::blocks_len( $ld_blocks['blocks'] );
		if ( $c_len >= self::MIN_TEXT && $c_len >= $l_len * 0.8 ) {
			$pick = $container + [ 'method' => 'container' ];
		} elseif ( $l_len >= self::MIN_TEXT ) {
			$pick = $ld_blocks + [ 'method' => 'json_ld' ];
		} elseif ( mb_strlen( trim( (string) ( $og['description'] ?? '' ) ) ) > max( $c_len, $l_len ) ) {
			// The page's own summary, cleaned like any other copy (2026-10-21: bluecataudio.com's said "our multi effects processor").
			$pick = self::clean_blocks( [ [ 'type' => 'paragraph', 'text' => trim( $og['description'] ) ] ], $skip ) + [ 'method' => 'meta_only' ];
		} elseif ( $c_len > 0 || $l_len > 0 ) {
			$pick = ( $c_len >= $l_len ? $container : $ld_blocks ) + [ 'method' => $c_len >= $l_len ? 'container_short' : 'json_ld_short' ];
		} else {
			$meta = trim( (string) ( $og['description'] ?? '' ) );
			$pick = $meta !== '' ? self::clean_blocks( [ [ 'type' => 'paragraph', 'text' => $meta ] ], $skip ) + [ 'method' => 'meta_only' ] : [ 'blocks' => [], 'removed' => [], 'method' => 'none' ];
		}
		$out['blocks']           = $pick['blocks'];
		$out['description_html'] = self::render( $pick['blocks'] );
		$out['text_chars']       = self::blocks_len( $pick['blocks'] );
		$out['method']           = $pick['method'];
		$out['removed']          = array_values( array_unique( $pick['removed'] ) );

		// Images: JSON-LD first (the vendor's own product data), then Open Graph, then the description
		// container's / gallery's pictures. Sizes from what the page states; 0 when unknown.
		$imgs = [];
		foreach ( $ld['images'] as $u ) {
			$imgs[] = [ 'url' => $u, 'width' => 0, 'height' => 0, 'source' => 'json_ld' ];
		}
		foreach ( $og['images'] as $i ) {
			$imgs[] = $i + [ 'source' => 'og' ];
		}
		$picked = [];
		if ( $dom && $profile && ! empty( $profile['images'] ) ) {
			foreach ( self::xpath_images( $dom, $profile['images'] ) as $i ) {
				$picked[] = $i + [ 'source' => 'profile' ];
			}
		}
		$imgs = $picked && ! empty( $profile['images_only'] ) ? $picked : array_merge( $imgs, $picked );
		// A person's pick says what the product's pictures are, and nothing is added to it (owner 2026-11-04, ilio.com: the page's
		// related products and SALE badges came along with the 4 gallery pictures the mapper said the pick gives).
		if ( $dom && ! ( $picked && ! empty( $profile['images_only'] ) ) ) {
			foreach ( self::gallery_images( $dom, $base ) as $i ) {
				$imgs[] = $i + [ 'source' => 'gallery' ];
			}
			// The page's own pictures when nothing but the share image (og:image) was found: one social banner
			// used to hide them all (owner 2026-10-08: reasonstudios.com/reason has 8 product AVIFs, we saw 1).
			$structured = array_filter( $imgs, static fn( $i ) => $i['source'] !== 'og' );
			if ( ! $structured && $product_name !== '' ) {
				// A product's card is all about the product, so its pictures are the product's whatever their files are called
				// ("geoshred-bansuri.png" for GeoBansuri, whose file doesn't name it).
				foreach ( ( str_contains( $html, self::CARD_MARK ) ? self::card_images( $dom ) : [] ) ?: self::named_images( $dom, $product_name ) as $i ) {
					$imgs[] = $i + [ 'source' => 'named' ];
				}
			}
		}
		$seen = [];
		foreach ( $imgs as $i ) {
			$u = self::absolute( html_entity_decode( trim( $i['url'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
			if ( $u === '' || preg_match( self::IMAGE_SKIP, $u ) || ! preg_match( '#^https?://#i', $u ) || MapCheck::skipped( $base, $u ) ) {
				continue; // MapCheck::skipped(): a picture the owner marked "not a product picture" on this site
			}
			$key = strtolower( preg_replace( [ '/[?#].*$/', '#^https?:#i' ], '', $u ) ); // the same picture over http and https, or at another width, once
			if ( isset( $seen[ $key ] ) ) {
				// Keep the larger stated size when the same picture shows up twice.
				$k = $seen[ $key ];
				if ( $i['width'] > $out['images'][ $k ]['width'] ) {
					$out['images'][ $k ]['width']  = $i['width'];
					$out['images'][ $k ]['height'] = $i['height'];
				}
				continue;
			}
			$seen[ $key ]    = count( $out['images'] );
			$out['images'][] = [
				'url'     => $u,
				'width'   => (int) $i['width'],
				'height'  => (int) $i['height'],
				'source'  => $i['source'],
				'low_res' => ( $i['width'] && $i['width'] < self::LOW_RES_EDGE ) || ( $i['height'] && $i['height'] < self::LOW_RES_EDGE ),
			];
		}
		return $out;
	}

	/* ── Sources ───────────────────────────────────────────────────────── */

	/** @return array{description:string,images:string[],name:string,brand:string,identifiers:array<string,string>,version:string,size:array<string,mixed>} */
	private static function json_ld_product( string $html ): array {
		$r = [ 'description' => '', 'images' => [], 'name' => '', 'brand' => '', 'identifiers' => [], 'version' => '', 'size' => [] ];
		if ( ! preg_match_all( '/<script[^>]+application\/ld\+json[^>]*>(.*?)<\/script>/is', $html, $mm ) ) {
			return $r;
		}
		$nodes = [];
		$walk  = static function ( $n ) use ( &$walk, &$nodes ) {
			if ( ! is_array( $n ) ) {
				return;
			}
			if ( isset( $n['@graph'] ) ) {
				$walk( $n['@graph'] );
			}
			if ( array_is_list( $n ) ) {
				foreach ( $n as $x ) {
					$walk( $x );
				}
				return;
			}
			$type = (array) ( $n['@type'] ?? [] );
			if ( array_intersect( $type, [ 'Product', 'SoftwareApplication', 'ProductGroup', 'IndividualProduct', 'MusicRecording' ] ) ) {
				$nodes[] = $n;
			}
		};
		foreach ( $mm[1] as $json ) {
			$walk( json_decode( trim( html_entity_decode( $json, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ), true ) ?? json_decode( trim( $json ), true ) );
		}
		foreach ( $nodes as $n ) {
			if ( $r['description'] === '' && is_string( $n['description'] ?? null ) ) {
				$r['description'] = $n['description'];
			}
			if ( $r['name'] === '' && is_string( $n['name'] ?? null ) ) {
				$r['name'] = trim( $n['name'] );
			}
			if ( $r['brand'] === '' ) {
				$b          = $n['brand'] ?? ( $n['manufacturer'] ?? '' );
				$r['brand'] = trim( (string) ( is_array( $b ) ? ( $b['name'] ?? '' ) : $b ) );
			}
			if ( $r['version'] === '' && is_scalar( $n['softwareVersion'] ?? null ) ) {
				$r['version'] = trim( (string) $n['softwareVersion'] );
			}
			foreach ( [ 'weight', 'width', 'height', 'depth' ] as $k ) {
				if ( ! isset( $r['size'][ $k ] ) && isset( $n[ $k ] ) ) {
					$r['size'][ $k ] = $n[ $k ]; // QuantitativeValue {value, unitCode|unitText} or "1.2 kg"
				}
			}
			foreach ( [ 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14', 'mpn', 'sku' ] as $k ) {
				if ( ! isset( $r['identifiers'][ $k ] ) && is_scalar( $n[ $k ] ?? null ) && trim( (string) $n[ $k ] ) !== '' ) {
					$r['identifiers'][ $k ] = trim( (string) $n[ $k ] );
				}
			}
			foreach ( (array) ( $n['image'] ?? [] ) as $k => $img ) {
				if ( is_string( $img ) ) {
					$r['images'][] = $img;
				} elseif ( is_array( $img ) ) {
					foreach ( array_is_list( $img ) ? $img : [ $img ] as $x ) {
						$u = is_string( $x ) ? $x : ( $x['contentUrl'] ?? ( $x['url'] ?? '' ) );
						if ( is_string( $u ) && $u !== '' ) {
							$r['images'][] = $u;
						}
					}
				}
			}
		}
		return $r;
	}

	/** @return array{description:string,images:array<int,array{url:string,width:int,height:int}>} */
	private static function open_graph( string $html ): array {
		$r = [ 'description' => '', 'images' => [] ];
		if ( ! preg_match_all( '/<meta\s[^>]*>/i', $html, $mm ) ) {
			return $r;
		}
		$cur = null;
		foreach ( $mm[0] as $tag ) {
			if ( ! preg_match( '/(?:property|name)\s*=\s*["\']([^"\']+)["\']/i', $tag, $p ) || ! preg_match( '/content\s*=\s*("([^"]*)"|\'([^\']*)\')/i', $tag, $c ) ) {
				continue;
			}
			$prop = strtolower( $p[1] );
			$val  = html_entity_decode( $c[2] !== '' ? $c[2] : ( $c[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( in_array( $prop, [ 'og:image', 'og:image:secure_url', 'og:image:url', 'twitter:image' ], true ) && $val !== '' ) {
				if ( $cur !== null && $prop !== 'og:image:secure_url' ) {
					$r['images'][] = $cur;
				}
				if ( $cur === null || $prop !== 'og:image:secure_url' ) {
					$cur = [ 'url' => $val, 'width' => 0, 'height' => 0 ];
				}
			} elseif ( $prop === 'og:image:width' && $cur ) {
				$cur['width'] = (int) $val;
			} elseif ( $prop === 'og:image:height' && $cur ) {
				$cur['height'] = (int) $val;
			} elseif ( $prop === 'og:title' && empty( $r['title'] ) ) {
				$r['title'] = trim( $val );
			} elseif ( in_array( $prop, [ 'og:description', 'description' ], true ) && $r['description'] === '' ) {
				$r['description'] = $val;
			}
		}
		if ( $cur ) {
			$r['images'][] = $cur;
		}
		return $r;
	}

	private static function dom( string $html, bool $embeds = false ): ?\DOMDocument {
		if ( ! class_exists( 'DOMDocument' ) || trim( $html ) === '' ) {
			return null;
		}
		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument();
		// No LIBXML_NOENT (entity substitution) and no network: the page is untrusted input.
		$ok = $doc->loadHTML( '<?xml encoding="UTF-8">' . self::without_scripts( $html ), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok ) {
			return null;
		}
		$xp = new \DOMXPath( $doc );
		// Tab bars too: their labels ("Features System Requirements") were read into the first paragraph (Undertone, 2026-10-08).
		$tabs = "//*[@role='tablist']|//*[@role='tab']|//*[contains(@class,'tab__header') or contains(@class,'tabs__header') or contains(@class,'tab-header') or contains(@class,'tabs-header') or contains(@class,'tabs__nav') or contains(@class,'tab-nav') or contains(@class,'tabs-nav') or contains(@class,'nav-tabs')]";
		foreach ( $xp->query( '//script|//style|//noscript|//template|//iframe|//svg|//nav|//header|//footer|//form|//button|//select|//aside|//*[@role="navigation"]|//*[@aria-hidden="true"]|' . $tabs ) as $n ) {
			if ( $embeds && $n->nodeName === 'iframe' && preg_match( self::EMBED_SRC, (string) $n->getAttribute( 'src' ) ) ) {
				$n->nodeValue = ''; // a map's Videos or Audio demos may name it; its fallback words aren't the page's
				continue;
			}
			$n->parentNode && $n->parentNode->removeChild( $n );
		}
		// The same parts built from plain elements and named so (CHROME_NAME): soundsonline.com's div#header-container,
		// div#header-banners and div#nav-outer were read as its products' descriptions (2026-10-26). Never one holding
		// most of the page: one maker's div.main-navigation-bar wraps all of it.
		$body = $xp->query( '//body' )->item( 0 );
		$most = $body ? mb_strlen( (string) preg_replace( '/\s+/u', '', $body->textContent ) ) * self::CHROME_SHARE : 0;
		foreach ( iterator_to_array( $xp->query( '//body//*[@id or @class]' ) ) as $n ) {
			if ( ! $n->parentNode || in_array( strtolower( $n->tagName ), [ 'main', 'article' ], true ) ) {
				continue;
			}
			foreach ( preg_split( '/\s+/', trim( $n->getAttribute( 'id' ) . ' ' . $n->getAttribute( 'class' ) ) ) as $name ) {
				if ( preg_match( self::CHROME_NAME, $name ) && mb_strlen( (string) preg_replace( '/\s+/u', '', $n->textContent ) ) < $most ) {
					$n->parentNode->removeChild( $n );
					break;
				}
			}
		}
		return $doc;
	}

	/**
	 * The page's description: every element that looks like a description container, plus <main>,
	 * <article> and <body>, is read and cleaned; the most focused one (least raw text) that still
	 * holds at least FOCUS of the best cleaned copy wins. A wrapper that adds only menus and shop
	 * chrome loses to the container inside it; a tiny blurb loses to the real description.
	 *
	 * @return array{blocks:array<int,array{type:string,text:string}>,removed:string[]}
	 */
	private static function description_blocks( \DOMDocument $doc, array $skip = [], string $page = '' ): array {
		$xp    = new \DOMXPath( $doc );
		$lower = "translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz')";
		$cands = [];
		foreach ( self::CONTAINER_HINTS as $hint ) {
			$q = strpos( $hint, '=' ) !== false ? '//*[@itemprop="description"]' : "//*[contains({$lower}, '{$hint}') or contains(translate(@id,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'), '{$hint}')]";
			foreach ( $xp->query( $q ) as $el ) {
				$cands[ spl_object_id( $el ) ] = $el;
			}
		}
		foreach ( $xp->query( '//main|//article|//*[@role="main"]|//body' ) as $el ) {
			$cands[ spl_object_id( $el ) ] = $el;
		}
		$read = [];
		foreach ( $cands as $el ) {
			$raw = mb_strlen( trim( preg_replace( '/\s+/', ' ', $el->textContent ) ) );
			if ( $raw < self::MIN_TEXT / 2 ) {
				continue;
			}
			$c      = self::clean_blocks( self::node_blocks( $el ), $skip, $page );
			$read[] = [ 'raw' => $raw, 'len' => self::blocks_len( $c['blocks'] ), 'c' => $c ];
		}
		if ( ! $read ) {
			return [ 'blocks' => [], 'removed' => [] ];
		}
		$max  = max( array_column( $read, 'len' ) );
		$keep = array_filter( $read, static fn( $r ) => $r['len'] >= $max * self::FOCUS );
		usort( $keep, static fn( $a, $b ) => $a['raw'] <=> $b['raw'] );
		return $keep[0]['c'];
	}

	/**
	 * A vendor profile's description: every element its query matches, in page order (a map may name
	 * several parts, or one selector may match each paragraph). Null when it has none or finds nothing.
	 */
	private static function profile_blocks( \DOMDocument $doc, ?array $profile, array $skip = [], string $page = '' ): ?array {
		if ( ! $profile || empty( $profile['description'] ) ) {
			return null;
		}
		$xp = new \DOMXPath( $doc );
		foreach ( $profile['description'] as $q ) {
			$c = self::clean_blocks( self::nodes_blocks( @$xp->query( $q ) ?: [] ), $skip, $page ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a malformed map query reads as nothing
			if ( self::blocks_len( $c['blocks'] ) > 0 ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * A mapped description, never less than the generic reading around it: the generic reading's feature
	 * lists it doesn't hold are added after its own blocks (each with the heading that introduces it), and
	 * a generic heading that introduces one of its paragraphs goes back in before that paragraph. What the
	 * map leaves out on purpose stays out: other paragraphs (sidebar blurbs, script text read as copy).
	 * Requirement lists stay out too (they go to the requirement fields). @return array{blocks:array,removed:array,kept:int}
	 */
	private static function keep_lists( array $mapped, array $generic ): array {
		$key  = static fn( array $b ) => mb_strtolower( mb_substr( self::text( (string) ( $b['text'] ?? '' ) ), 0, 80 ) );
		$have = [];
		$at   = [];
		foreach ( $mapped['blocks'] as $i => $b ) {
			$at[ $key( $b ) ] = $at[ $key( $b ) ] ?? $i;
			foreach ( $b['type'] === 'list' ? (array) $b['items'] : [ (string) ( $b['text'] ?? '' ) ] as $t ) {
				$have[ mb_strtolower( self::text( (string) $t ) ) ] = true;
			}
		}
		$kept   = 0;
		$add    = [];
		$before = []; // mapped index => headings to put back in front of it
		$prior  = null;
		foreach ( $generic['blocks'] as $b ) {
			if ( $b['type'] === 'heading' ) {
				$prior = $b;
				continue;
			}
			$heading = $prior && ! isset( $have[ mb_strtolower( self::text( (string) $prior['text'] ) ) ] ) ? $prior : null;
			$prior   = null;
			if ( isset( $at[ $key( $b ) ] ) ) {
				if ( $heading ) {
					$before[ $at[ $key( $b ) ] ][] = $heading;
					$have[ mb_strtolower( self::text( (string) $heading['text'] ) ) ] = true;
					$kept++;
				}
				continue;
			}
			if ( $b['type'] === 'list' && count( (array) $b['items'] ) >= 3 && ! self::looks_like_requirements( (string) $b['text'] ) ) {
				$new = array_filter( (array) $b['items'], static fn( $i ) => ! isset( $have[ mb_strtolower( self::text( (string) $i ) ) ] ) );
				if ( count( $new ) >= 3 ) {
					if ( $heading ) {
						$add[] = $heading;
					}
					$add[] = $b;
					$kept++;
				}
			}
		}
		$out = [];
		foreach ( $mapped['blocks'] as $i => $b ) {
			array_push( $out, ...( $before[ $i ] ?? [] ) );
			$out[] = $b;
		}
		return [ 'blocks' => array_merge( $out, $add ), 'removed' => $mapped['removed'], 'kept' => $kept ];
	}

	const MAX_PARTS = 40; // matched elements one mapped part reads

	/** Blocks of several elements in page order; an element inside one already read is skipped. */
	private static function nodes_blocks( iterable $nodes ): array {
		$seen   = [];
		$blocks = [];
		$n      = 0;
		foreach ( $nodes as $el ) {
			if ( ! $el instanceof \DOMElement || $n >= self::MAX_PARTS ) {
				continue;
			}
			for ( $up = $el->parentNode; $up; $up = $up->parentNode ) {
				if ( isset( $seen[ spl_object_id( $up ) ] ) ) {
					continue 2;
				}
			}
			$seen[ spl_object_id( $el ) ] = true;
			$n++;
			array_push( $blocks, ...self::node_blocks( $el, true ) ); // the element itself: a picked <p> is one paragraph, links and emphasis in it included
		}
		return $blocks;
	}

	/** @return array<int,array{url:string,width:int,height:int}> */
	public static function xpath_images( \DOMDocument $doc, array $queries ): array { // public: MapCheck reads picks with their sizes
		$xp  = new \DOMXPath( $doc );
		$out = [];
		foreach ( $queries as $q ) {
			foreach ( @$xp->query( $q ) ?: [] as $img ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a malformed map query reads as nothing
				if ( ! $img instanceof \DOMElement ) {
					continue;
				}
				[ $url, $w ] = self::img_best( $img );
				if ( $url !== '' && ! self::in_video( $img ) ) {
					$out[] = [ 'url' => $url, 'width' => $w ?: (int) $img->getAttribute( 'width' ), 'height' => $w ? 0 : (int) $img->getAttribute( 'height' ) ];
				}
			}
		}
		return $out;
	}

	/**
	 * This picture stands for something else on the site: in a section named as other products, or a link to another of
	 * its pages (a tile, a menu's picture). Measured on 2,500 stored product pages: 2,161 of 6,996 gallery pictures, and the
	 * 12 pages left with none were showing only other products. A link to the same page, or to its parent or child
	 * (Shopify's /collections/x/products/y), is this product's.
	 */
	public static function other_product( \DOMElement $img, string $base ): bool {
		$page = (array) wp_parse_url( $base );
		$here = rtrim( (string) ( $page['path'] ?? '' ), '/' );
		$site = VendorMaps::host( (string) ( $page['host'] ?? '' ) );
		$up   = $img->parentNode;
		for ( $i = 0; $i < self::OTHER_CLIMB && $up instanceof \DOMElement && ! in_array( $up->nodeName, [ 'body', 'html', 'main' ], true ); $i++, $up = $up->parentNode ) {
			if ( preg_match( self::OTHER_PRODUCTS, $up->getAttribute( 'class' ) . ' ' . $up->getAttribute( 'id' ) ) ) {
				return true;
			}
			$href = $up->nodeName === 'a' ? trim( $up->getAttribute( 'href' ) ) : '';
			if ( $href === '' || $href[0] === '#' || $base === '' || preg_match( '/\.(?:jpe?g|png|webp|gif|avif)(?:[?#]|$)|^(?:mailto|tel|javascript):/i', $href ) ) {
				continue; // a lightbox's full-size picture, or nowhere
			}
			$to   = (array) wp_parse_url( self::absolute( $href, $base ) );
			$path = rtrim( (string) ( $to['path'] ?? '' ), '/' );
			if ( $path !== '' && VendorMaps::host( (string) ( $to['host'] ?? '' ) ) === $site && basename( $path ) !== basename( $here ) && ! str_starts_with( $here . '/', $path . '/' ) && ! str_starts_with( $path . '/', $here . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** This picture is the poster of a click-to-play video: alone in an element that plays one. The videos are read as videos. */
	public static function in_video( \DOMElement $img ): bool {
		$up = $img->parentNode;
		for ( $i = 0; $i < self::VIDEO_CLIMB && $up instanceof \DOMElement; $i++, $up = $up->parentNode ) {
			if ( $up->getElementsByTagName( 'img' )->length > 1 || mb_strlen( trim( $up->textContent ) ) > self::VIDEO_WORDS ) {
				return false;
			}
			foreach ( $up->attributes as $a ) {
				$is_type = $a->name === 'data-media-type' && preg_match( '/video/i', $a->value );
				$is_addr = in_array( $a->name, [ 'href', 'data-href', 'data-src', 'data-url' ], true ) && preg_match( self::VIDEO_ADDR, $a->value );
				if ( $is_type || $is_addr || ( $a->value !== '' && preg_match( self::VIDEO_ATTR, $a->name ) ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** An <img>'s largest source: srcset's widest, else zoom/large/lazy attributes, else src. @return array{0:string,1:int} url, width (0 = unknown) */
	private static function img_best( \DOMElement $img ): array {
		$best = [ '', 0 ];
		foreach ( [ 'srcset', 'data-srcset', 'data-lazy-srcset' ] as $attr ) {
			foreach ( array_map( 'trim', explode( ',', (string) $img->getAttribute( $attr ) ) ) as $cand ) {
				if ( preg_match( '/^(\S+)\s+(\d+)w$/', $cand, $m ) && (int) $m[2] > $best[1] ) {
					$best = [ $m[1], (int) $m[2] ];
				}
			}
		}
		if ( $best[0] === '' ) {
			foreach ( [ 'data-zoom-image', 'data-large_image', 'data-src', 'data-lazy-src', 'data-original', 'src' ] as $attr ) {
				$v = trim( (string) $img->getAttribute( $attr ) );
				if ( $v !== '' && ! str_starts_with( $v, 'data:' ) ) {
					return [ $v, 0 ];
				}
			}
			// Lazy loaders keep the real file in an attribute of their own (Salient: data-nectar-img-src).
			foreach ( $img->attributes as $a ) {
				if ( str_contains( strtolower( $a->name ), 'src' ) && preg_match( '#^(https?:)?//|^/[^/]#', trim( $a->value ) ) && preg_match( '/\.(jpe?g|png|webp|avif|gif)(\?|$)/i', trim( $a->value ) ) ) {
					return [ trim( $a->value ), 0 ];
				}
			}
		}
		return $best;
	}

	/* ── For vendor maps (VendorMaps) ───────────────────────────────────── */

	/** The page as the reader sees it (scripts, menus, header, footer, forms and tab bars removed; pictures a script would draw, drawn). */
	/**
	 * The page without its <script> and <style> blocks, before libxml reads it. libxml ends a script by HTML 4's rule, at the
	 * first "</" and a letter, so a script whose strings hold HTML spilled its code into the page's text: SKU 1143-1600's
	 * description carried ikmultimedia.com/registration's `",go_to_ua); } else { tracking_pars=…` (owner 2026-10-27). JSON-LD
	 * is read from the raw page (ld()), and script_content() works on the raw page before this.
	 */
	public static function without_scripts( string $html ): string {
		$out = preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $html );
		return is_string( $out ) ? $out : $html;
	}

	public static function parse( string $html ): ?\DOMDocument {
		return self::dom( self::script_content( $html ), true );
	}

	const EMBED_SRC        = '~youtube(?:-nocookie)?\.com/|youtu\.be/|player\.vimeo\.com/|soundcloud\.com/~i'; // embedded players a map reads: every other frame goes
	const GALLERY_CLASS    = 'mmpt-gallery';
	const MAGENTO_GALLERY  = 'mage/gallery/gallery'; // the script a Magento gallery's data is addressed to
	const GALLERY_MAX      = 30;    // pictures taken from one element's JSON
	const GALLERY_JSON_MAX = 65536; // bytes of JSON in one element
	const GALLERY_PICTURE  = '/\.(?:png|jpe?g|webp|avif|gif)(?:[?#]|$)/i';
	const JSON_CLASS       = 'mmpt-json';
	const JSON_HTML        = '#<(?:p|div|ul|ol|li|br|strong|b|h[1-6]|table|tr|td)\b#i';
	const JSON_HTML_MIN    = 120; // characters: shorter strings are labels and taglines (waves' subscription popup)
	const JSON_HTML_MAX    = 12;  // HTML strings in one element: more is a catalog of other products, not this page's content

	/**
	 * Pictures and content a script would draw, which the stored copy (no scripts) shows as nothing (owner 2026-10-17/18, waves.com):
	 *
	 * - imgix: <img ix-path="/images/…png"> or <img ix-src="https://…"> with no src, which imgix.js fills in. They get
	 *   a src: ix-src, or ix-path on the page's <meta property="ix:host"> (waves: media.wavescdn.com).
	 * - Galleries a script draws from JSON in the page. waves.com keeps them as
	 * <div slider-dots class=hidden>[{ "imgpath": "/images/products/…/x.png" }, …]</div>, which slider-dots.js
	 * turns into CSS backgrounds; the stored copy runs no script, so there was nothing to see or pick (owner
	 * 2026-10-17). An element whose whole text is JSON naming pictures gets them as <img> instead, with the class
	 * mmpt-gallery (the same on every stored page of the site, so a pick gets a steady selector; the live view
	 * shows the element). A root-relative path takes the host of the page's own absolute URLs that share the most
	 * of its folders, at least two (waves: media.wavescdn.com/images/products/…; www.waves.com answers 404), else
	 * it stays relative to the page (ix:host first, when the page names one).
	 * - Content held as HTML inside page data (waves' Tech Specs tab: <div id=tab-sys-req-json class=hidden>{"systemRequirements":
	 *   [{"platform":1,"content":"<div><strong>CPU</strong>…"}]}</div>, rendered by its app). An element whose whole text is JSON
	 *   holding 1 to 12 HTML strings of 120+ characters gets them as HTML (wp_kses_post), each in a .mmpt-json-part with its
	 *   key path, under the class mmpt-json, so a tab shows its content and it can be picked and read.
	 *
	 * - Magento's product gallery (ilio.com; owner 2026-11-04): magento_gallery().
	 *
	 * Maps only (parse(), the live view): the generic reader doesn't change.
	 */
	public static function script_content( string $html ): string {
		$html  = self::magento_gallery( $html );
		$imgix = stripos( $html, 'ix-path' ) !== false || stripos( $html, 'ix-src' ) !== false;
		if ( ! $imgix && ! preg_match( '/>\s*[\[{]\s*(?:"|&quot;|\[|\{)/', $html ) ) {
			return $html; // no element starts with JSON and no imgix picture: nothing a script would draw
		}
		$hosts = null;
		$ix    = preg_match( '/<meta\b[^>]*\bproperty\s*=\s*["\']?ix:host["\']?[^>]*\bcontent\s*=\s*["\']?([a-z0-9.-]+\.[a-z]{2,})/i', $html, $hm ) ? strtolower( $hm[1] ) : '';
		if ( $imgix ) {
			$drawn = preg_replace_callback(
				'#<img\b(?=[^>]*\bix-(?:path|src)\s*=)(?![^>]*(?<![\w-])src\s*=)([^>]*)>#i',
				static function ( array $m ) use ( $html, &$hosts, $ix ): string {
					$attr = static fn( string $name ) => preg_match( '/(?<![\w-])' . $name . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i', $m[1], $v ) ? html_entity_decode( $v[1] . ( $v[2] ?? '' ) . ( $v[3] ?? '' ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) : '';
					$url  = $attr( 'ix-src' );
					if ( $url === '' && ( $p = $attr( 'ix-path' ) ) !== '' ) {
						$url = self::gallery_url( $p, $hosts ??= self::page_url_folders( $html ), $ix );
					}
					return $url === '' ? $m[0] : '<img src="' . esc_attr( $url ) . '"' . $m[1] . '>';
				},
				$html
			);
			$html  = is_string( $drawn ) ? $drawn : $html;
			$hosts = null;
		}
		$out   = preg_replace_callback(
			'#<(div|span|p|section|ul)\b([^>]*)>\s*([\[{][^<]*[\]}])\s*</\1\s*>#i',
			static function ( array $m ) use ( $html, &$hosts, $ix ): string {
				if ( strlen( $m[3] ) > self::GALLERY_JSON_MAX || stripos( $m[2], self::GALLERY_CLASS ) !== false || stripos( $m[2], self::JSON_CLASS ) !== false ) {
					return $m[0];
				}
				$data  = json_decode( html_entity_decode( $m[3], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), true );
				$paths = self::gallery_paths( $data );
				if ( ! $paths ) {
					$parts = is_array( $data ) ? self::json_html( $data ) : [];
					if ( ! $parts ) {
						return $m[0];
					}
					$body = '';
					foreach ( $parts as [ $key, $part ] ) {
						$body .= '<div class="mmpt-json-part" data-mmpt-key="' . esc_attr( $key ) . '">' . wp_kses_post( $part ) . '</div>';
					}
					return '<' . $m[1] . self::with_class( $m[2], self::JSON_CLASS ) . '>' . $body . '</' . $m[1] . '>';
				}
				$hosts ??= self::page_url_folders( $html );
				$imgs     = '';
				foreach ( array_slice( $paths, 0, self::GALLERY_MAX ) as $p ) {
					$imgs .= '<img src="' . esc_attr( self::gallery_url( $p, $hosts, $ix ) ) . '" alt="">';
				}
				return '<' . $m[1] . self::with_class( $m[2], self::GALLERY_CLASS ) . '>' . $imgs . '</' . $m[1] . '>';
			},
			$html
		);
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Magento's product gallery, drawn where its script draws it. A delivered page has one placeholder picture in
	 * <div data-gallery-role=gallery-placeholder>; every picture is in a text/x-magento-init script, which Fotorama reads
	 * and Magento then removes from the page. The placeholder gets the gallery's own `full` addresses, so a pick of it reads
	 * them all (ilio.com: 4 pictures, not 1). Videos stay out: they aren't pictures, and their `img` is a poster.
	 */
	private static function magento_gallery( string $html ): string {
		if ( ! str_contains( $html, self::MAGENTO_GALLERY ) || ! preg_match_all( '#<script\b[^>]*text/x-magento-init[^>]*>(.*?)</script\s*>#is', $html, $mm ) ) {
			return $html;
		}
		$imgs = '';
		foreach ( $mm[1] as $json ) {
			if ( ! str_contains( $json, self::MAGENTO_GALLERY ) ) {
				continue;
			}
			$data  = json_decode( $json, true );
			$items = $data['[data-gallery-role=gallery-placeholder]'][ self::MAGENTO_GALLERY ]['data'] ?? [];
			foreach ( array_slice( is_array( $items ) ? $items : [], 0, self::GALLERY_MAX ) as $i ) {
				$url = (string) ( $i['full'] ?? $i['img'] ?? '' );
				if ( ( $i['type'] ?? 'image' ) === 'image' && preg_match( '#^https?://#i', $url ) ) {
					$imgs .= '<img src="' . esc_attr( $url ) . '" alt="' . esc_attr( (string) ( $i['caption'] ?? '' ) ) . '">';
				}
			}
			break;
		}
		if ( $imgs === '' ) {
			return $html;
		}
		$out = preg_replace_callback(
			'#(<div\b[^>]*data-gallery-role\s*=\s*["\']gallery-placeholder["\'][^>]*>).*?(</div\s*>)#is',
			static fn( array $m ): string => $m[1] . $imgs . $m[2],
			$html,
			1
		);
		return is_string( $out ) ? $out : $html;
	}

	/**
	 * The pictures of a JSON gallery, [] when it isn't one. A gallery is a list whose every item is a picture path
	 * or a small object (at most 4 keys) holding one: waves' [{ "imgpath": "…png" }, …]. A data object that merely
	 * mentions a picture (waves' 14 KB #efs-data product record) is not, and stays as it is.
	 */
	private static function gallery_paths( $data ): array {
		if ( ! is_array( $data ) || ! $data || ! array_is_list( $data ) ) {
			return [];
		}
		$is_pic = static fn( $v ) => is_string( $v ) && strlen( $v ) < 500 && ! preg_match( '/\s/', $v ) && preg_match( self::GALLERY_PICTURE, $v );
		$paths  = [];
		foreach ( $data as $item ) {
			$pics = is_array( $item ) && count( $item ) <= 4 ? array_values( array_filter( $item, $is_pic ) ) : ( $is_pic( $item ) ? [ $item ] : [] );
			if ( ! $pics ) {
				return []; // one item that isn't a picture: not a gallery
			}
			$paths[ $pics[0] ] = true;
		}
		return array_keys( $paths );
	}

	/** An element's attributes with $class added first to its class (quoted or not: waves writes class=hidden). */
	private static function with_class( string $attrs, string $class ): string {
		$re = '/(?<![\w-])class\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/i';
		return preg_match( $re, $attrs )
			? (string) preg_replace_callback( $re, static fn( $c ) => 'class="' . $class . ' ' . esc_attr( $c[1] . ( $c[2] ?? '' ) . ( $c[3] ?? '' ) ) . '"', $attrs, 1 )
			: $attrs . ' class="' . $class . '"';
	}

	/**
	 * The HTML strings in a page's JSON as [ key path, html ] (see script_content()), [] when there are none or more than
	 * JSON_HTML_MAX. Key path: "systemRequirements › 2 › content" (list items counted from 1).
	 */
	private static function json_html( array $data ): array {
		$out  = [];
		$walk = static function ( array $node, string $path ) use ( &$walk, &$out ): bool {
			foreach ( $node as $k => $v ) {
				$key = ltrim( $path . ' › ' . ( is_int( $k ) ? (string) ( $k + 1 ) : (string) $k ), ' ›' );
				if ( is_array( $v ) ) {
					if ( ! $walk( $v, $key ) ) {
						return false;
					}
				} elseif ( is_string( $v ) && strlen( $v ) >= self::JSON_HTML_MIN && preg_match( self::JSON_HTML, $v ) ) {
					$out[] = [ $key, $v ];
					if ( count( $out ) > self::JSON_HTML_MAX ) {
						return false;
					}
				}
			}
			return true;
		};
		return $walk( $data, '' ) ? $out : [];
	}

	/** The page's absolute URLs as [ host, folders ], one per host and folder (for gallery_url()). */
	private static function page_url_folders( string $html ): array {
		$seen = [];
		if ( preg_match_all( '#https?://([a-z0-9.-]+\.[a-z]{2,})(/[^\s"\'<>()&;?]*)#i', $html, $all, PREG_SET_ORDER ) ) {
			foreach ( array_slice( $all, 0, 4000 ) as $u ) {
				$dir                                       = trim( dirname( $u[2] . 'x' ), '/' );
				$seen[ strtolower( $u[1] ) . '|' . $dir ] = [ strtolower( $u[1] ), $dir === '' || $dir === '.' ? [] : explode( '/', $dir ) ];
			}
		}
		return array_values( $seen );
	}

	/** A picture path a script would use, as an address (see script_content()); $ix: the page's imgix host. */
	private static function gallery_url( string $path, array $hosts, string $ix = '' ): string {
		if ( preg_match( '#^https?://#i', $path ) ) {
			return $path;
		}
		if ( str_starts_with( $path, '//' ) ) {
			return 'https:' . $path;
		}
		if ( ! str_starts_with( $path, '/' ) ) {
			return $path; // relative to the page (the live view's <base>, the reader's img_src())
		}
		if ( $ix !== '' ) {
			return 'https://' . $ix . $path;
		}
		$want = array_values( array_filter( explode( '/', trim( dirname( $path ), '/' ) ), 'strlen' ) );
		$best = '';
		$most = 1; // at least two shared folders ("/images/products"): one ("/images") says little
		foreach ( $hosts as [ $host, $dirs ] ) {
			$n = 0;
			while ( isset( $want[ $n ], $dirs[ $n ] ) && $want[ $n ] === $dirs[ $n ] ) {
				++$n;
			}
			if ( $n > $most ) {
				$most = $n;
				$best = $host;
			}
		}
		return $best !== '' ? 'https://' . $best . $path : $path;
	}

	/** An element's cleaned blocks (paragraphs, lists, headings), as the description reader reads them on $url's site. */
	public static function element_blocks( \DOMNode $el, string $url = '' ): array {
		return self::clean_blocks( self::node_blocks( $el ), $url !== '' ? MapCheck::text_skip_set( $url ) : [], $url )['blocks'];
	}

	/** Every matched element's cleaned blocks, in page order (nested matches read once), as read on $url's site. */
	public static function elements_blocks( iterable $nodes, string $url = '' ): array {
		return self::clean_blocks( self::nodes_blocks( $nodes ), $url !== '' ? MapCheck::text_skip_set( $url ) : [], $url )['blocks'];
	}

	/** An <img>'s best absolute URL ('' for none). */
	public static function img_src( \DOMElement $img, string $base ): string {
		return self::absolute( html_entity_decode( self::img_best( $img )[0], ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
	}

	/** Absolute picture URLs under XPath queries, skipping logos, icons and badges. @return string[] */
	public static function images_at( \DOMDocument $doc, array $queries, string $base ): array {
		$out = [];
		foreach ( self::xpath_images( $doc, $queries ) as $i ) {
			$u = self::absolute( html_entity_decode( trim( $i['url'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $base );
			if ( $u !== '' && preg_match( '#^https?://#i', $u ) && ! preg_match( self::IMAGE_SKIP, $u ) && ! MapCheck::skipped( $base, $u ) ) {
				$out[ strtolower( preg_replace( [ '/[?#].*$/', '#^https?:#i' ], '', $u ) ) ] = $u;
			}
		}
		return array_values( $out );
	}

	public static function absolute_url( string $u, string $base ): string {
		return self::absolute( $u, $base );
	}

	/** @return array<int,array{url:string,width:int,height:int}> */
	private static function gallery_images( \DOMDocument $doc, string $base = '' ): array {
		$xp  = new \DOMXPath( $doc );
		$out = [];
		$q   = "//*[contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'gallery') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'product-image') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'product__media') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'slider') or contains(translate(@class,'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'carousel')]//img";
		foreach ( $xp->query( $q ) as $img ) {
			$best = [ 'url' => '', 'w' => 0 ];
			foreach ( [ 'srcset', 'data-srcset' ] as $attr ) {
				foreach ( array_map( 'trim', explode( ',', (string) $img->getAttribute( $attr ) ) ) as $cand ) {
					if ( preg_match( '/^(\S+)\s+(\d+)w$/', $cand, $m ) && (int) $m[2] > $best['w'] ) {
						$best = [ 'url' => $m[1], 'w' => (int) $m[2] ];
					}
				}
			}
			$url = $best['url'] ?: ( $img->getAttribute( 'data-zoom-image' ) ?: ( $img->getAttribute( 'data-large_image' ) ?: ( $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'src' ) ) ) );
			if ( $url === '' || self::in_video( $img ) || self::other_product( $img, $base ) ) {
				continue;
			}
			$w     = $best['w'] ?: (int) ( $img->getAttribute( 'data-large_image_width' ) ?: $img->getAttribute( 'width' ) );
			$h     = (int) ( $img->getAttribute( 'data-large_image_height' ) ?: $img->getAttribute( 'height' ) );
			$out[] = [ 'url' => $url, 'width' => $w, 'height' => $best['w'] ? 0 : $h ];
			if ( count( $out ) >= 20 ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * Pages with no product markup: the pictures whose file path carries a distinctive word of the
	 * product's name ("kontrast-screen-6.webp" for Kontrast, "Albino-3_Legend" for Albino 3 Legend).
	 * Social icons, cart buttons and cross-sell tiles don't, so they're left out.
	 *
	 * @return array<int,array{url:string,width:int,height:int}>
	 */
	/**
	 * The pictures of a product's card (SharedPages::card()); IMAGE_SKIP still leaves out icons and video thumbnails. None
	 * from more than CARD_PICTURES: that's a section of a page taken for a card, and its people and awards aren't the product
	 * (measured 2026-11-05: soundsonline's Hollywood Fantasy Orchestra went from 11 pictures to 85 without this).
	 */
	private static function card_images( \DOMDocument $doc ): array {
		if ( $doc->getElementsByTagName( 'img' )->length > self::CARD_PICTURES ) {
			return [];
		}
		$out = [];
		foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
			$url = $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'src' );
			if ( $url !== '' ) {
				$out[] = [ 'url' => $url, 'width' => (int) $img->getAttribute( 'width' ), 'height' => (int) $img->getAttribute( 'height' ) ];
			}
		}
		return $out;
	}

	private static function named_images( \DOMDocument $doc, string $name ): array {
		$stop   = [ 'download', 'license', 'upgrade', 'crossgrade', 'bundle', 'edition', 'plugin', 'plug', 'software', 'virtual', 'instrument', 'with', 'from', 'student', 'teacher', 'boxed', 'cable', 'male', 'female' ];
		$tokens = array_values( array_filter( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( preg_replace( '/\([^)]*\)/', ' ', $name ) ) ), static fn( $t ) => mb_strlen( $t ) >= 4 && ! ctype_digit( $t ) && ! in_array( $t, $stop, true ) ) );
		if ( ! $tokens ) {
			return [];
		}
		$out = [];
		foreach ( $doc->getElementsByTagName( 'img' ) as $img ) {
			$url = $img->getAttribute( 'data-src' ) ?: $img->getAttribute( 'src' );
			if ( preg_match( '/(bg[-_]|background|award|mugshot|headshot|portrait|testimonial|shapes?[-_])/i', $url ) ) {
				continue; // decoration and people, not the product
			}
			$path = mb_strtolower( preg_replace( '/[^\p{L}\p{N}]+/u', '', (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			foreach ( $tokens as $t ) {
				if ( $path !== '' && strpos( $path, $t ) !== false ) {
					$out[] = [ 'url' => $url, 'width' => (int) $img->getAttribute( 'width' ), 'height' => (int) $img->getAttribute( 'height' ) ];
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * The page is about another version: a word both names share is followed by a different number
	 * ("RX 10" vs "RX 12", "EP-4" vs "EP-5"). Sizes and counts ("2 ft", "8-channel") don't count, and a
	 * number on only one side decides nothing.
	 */
	private static function other_version( string $product, string $page ): bool {
		$pairs = static function ( string $s ): array {
			$s = preg_replace( '/\([^)]*\)/', ' ', mb_strtolower( $s ) );
			preg_match_all( '/(\p{L}[\p{L}]+)[\s-]+(\d{1,2}(?:\.\d)?)(?![\d.]|\s*(?:ft|feet|mm|cm|in|inch|ch|channel|tb|gb|khz|bit|%|"|\'))/u', $s, $m, PREG_SET_ORDER );
			$out = [];
			foreach ( $m as $x ) {
				$out[ $x[1] ][] = $x[2];
			}
			return $out;
		};
		$a = $pairs( $product );
		$b = $pairs( $page );
		foreach ( $a as $word => $nums ) {
			if ( isset( $b[ $word ] ) && ! array_intersect( $nums, $b[ $word ] ) ) {
				return true;
			}
		}
		return false;
	}

	/* ── Blocks ────────────────────────────────────────────────────────── */

	/**
	 * Walk a container in document order: h2–h4 → heading, p / text div → paragraph, ul/ol → list. $self: $root is read
	 * as a block itself, not only what's inside it. Inside a picked <p>, the walk took its loose text alone and dropped
	 * every short link, <em> and <strong> between (owner 2026-11-04: "for ® version 2 and 3", Omnisphere's name gone).
	 */
	private static function node_blocks( \DOMNode $root, bool $self = false ): array {
		$blocks = [];
		$walk   = static function ( iterable $nodes ) use ( &$walk, &$blocks ) {
			foreach ( $nodes as $c ) {
				if ( count( $blocks ) >= self::WALK_LIMIT ) {
					return;
				}
				if ( $c instanceof \DOMText ) {
					$t = self::text( $c->textContent );
					if ( mb_strlen( $t ) >= 40 ) {
						$blocks[] = [ 'type' => 'paragraph', 'text' => $t ];
					}
					continue;
				}
				if ( ! $c instanceof \DOMElement ) {
					continue;
				}
				$tag = strtolower( $c->tagName );
				if ( in_array( $tag, [ 'h1', 'h2', 'h3', 'h4', 'h5' ], true ) ) {
					$t = implode( ' ', self::br_paragraphs( $c ) ); // a <br> in a heading parts its words
					if ( $t !== '' ) {
						$card     = self::in_link( $c );
						$blocks[] = [ 'type' => 'heading', 'text' => $t ] + ( $card !== '' ? [ 'card' => $card ] : [] );
					}
				} elseif ( $tag === 'p' || $tag === 'blockquote' ) {
					// "<br><br>" inside one <p> separates paragraphs (Shopify editors): "…MPEQ-1?As hardware" ran together.
					$parts = self::br_paragraphs( $c );
					$link  = count( $parts ) === 1 && self::link_only( $c ); // the whole paragraph is a link: a button or "Learn more…"
					foreach ( $parts as $t ) {
						$blocks[] = [ 'type' => 'paragraph', 'text' => $t ] + ( $link ? [ 'link' => true ] : [] );
					}
				} elseif ( $tag === 'ul' || $tag === 'ol' ) {
					$items = [];
					foreach ( $c->childNodes as $li ) {
						if ( $li instanceof \DOMElement && strtolower( $li->tagName ) === 'li' ) {
							$t = self::text( $li->textContent );
							if ( $t !== '' ) {
								$items[] = $t;
							}
						}
					}
					if ( $items ) {
						$blocks[] = [ 'type' => 'list', 'items' => $items, 'text' => implode( ' ', $items ) ];
					}
				} elseif ( in_array( $tag, [ 'img', 'picture', 'video', 'figure', 'table' ], true ) ) {
					continue;
				} elseif ( ! self::has_block_child( $c ) ) {
					// A div/span holding only inline markup ("…as <b>well</b>. Pristine…") is one paragraph,
					// not a sentence split at every tag.
					$t = self::text( $c->textContent );
					if ( mb_strlen( $t ) >= 40 || ( $t !== '' && preg_match( '/[.!?]$/', $t ) ) ) {
						$blocks[] = [ 'type' => 'paragraph', 'text' => $t ] + ( self::link_only( $c ) ? [ 'link' => true ] : [] );
					}
				} else {
					$walk( $c->childNodes );
				}
			}
		};
		$walk( $self ? [ $root ] : $root->childNodes );
		return $blocks;
	}

	/** The link a heading sits inside ('' for none or an in-page anchor): the heading may be a card. */
	private static function in_link( \DOMElement $el ): string {
		$n = $el->parentNode;
		for ( $i = 0; $i < 3 && $n instanceof \DOMElement; $i++, $n = $n->parentNode ) {
			if ( strtolower( $n->tagName ) === 'a' ) {
				$href = trim( (string) $n->getAttribute( 'href' ) );
				return $href !== '' && $href[0] !== '#' ? $href : '';
			}
		}
		return '';
	}

	/**
	 * A card for another of the site's products: a heading inside a link to a page beside this one
	 * (/products/thu next to /products/wild-collection: Overloud's "THU", "Bundle of 3 Rig Libraries",
	 * 2026-10-14). A link into a deeper part of the site is the product's own (Faber's tool pages).
	 */
	private static function other_product_card( string $href, string $page ): bool {
		if ( $href === '' || $page === '' ) {
			return false;
		}
		$abs = self::absolute( html_entity_decode( $href, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $page );
		if ( $abs === '' || ! PageStore::same_site( $abs, $page ) ) {
			return false;
		}
		$a = rtrim( (string) wp_parse_url( $abs, PHP_URL_PATH ), '/' );
		$b = rtrim( (string) wp_parse_url( $page, PHP_URL_PATH ), '/' );
		return $a !== '' && $b !== '' && $a !== $b && dirname( $a ) === dirname( $b );
	}

	/** All (or nearly all) of an element's text is link text: a button, "See product details…". */
	private static function link_only( \DOMElement $el ): bool {
		$all = mb_strlen( self::text( $el->textContent ) );
		if ( ! $all ) {
			return false;
		}
		if ( strtolower( $el->tagName ) === 'a' ) {
			return true;
		}
		$in = 0;
		foreach ( $el->getElementsByTagName( 'a' ) as $a ) {
			$in += mb_strlen( self::text( $a->textContent ) );
		}
		return $in >= 0.9 * $all;
	}

	private static function has_block_child( \DOMElement $el ): bool {
		foreach ( $el->getElementsByTagName( '*' ) as $d ) {
			if ( in_array( strtolower( $d->tagName ), [ 'p', 'div', 'ul', 'ol', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table', 'section', 'article', 'blockquote', 'br' ], true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Paragraphs/headings/lists from an HTML fragment (JSON-LD descriptions are often HTML or plain text with newlines). */
	private static function html_to_blocks( string $fragment ): array {
		$fragment = html_entity_decode( $fragment, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( strpos( $fragment, '<' ) === false ) {
			return array_values( array_map( static fn( $p ) => [ 'type' => 'paragraph', 'text' => self::text( $p ) ], array_filter( preg_split( '/\n\s*\n|\r\n\s*\r\n/', $fragment ), static fn( $p ) => trim( $p ) !== '' ) ) );
		}
		$doc = self::dom( '<html><body><div id="mmpt-root">' . $fragment . '</div></body></html>' );
		$el  = $doc ? $doc->getElementById( 'mmpt-root' ) : null;
		return $el ? self::node_blocks( $el ) : [];
	}

	/**
	 * Drop shop chrome, calls to action and repeats; leave out the vendor's store and install parts and
	 * the lines the owner marked on this site ($skip: MapCheck::text_skip_set()); stop at the first
	 * heading that starts a non-description part.
	 */
	/**
	 * An import's features (filter mmi_pipeline_import_value): cleaned as FeedFill cleaned them, of maker voice,
	 * calls to action and shop text, so the pipeline's import can take over FeedFill's job (Product Data
	 * migration phase 3b). Other concepts pass through unchanged.
	 *
	 * @param mixed $value The import's value: a JSON list or a list.
	 * @return mixed
	 */
	public static function clean_import_value( $value, string $concept, int $product_id = 0 ) {
		if ( $concept !== 'features' ) {
			return $value;
		}
		$list = is_string( $value ) ? json_decode( $value, true ) : $value;
		if ( ! is_array( $list ) ) {
			return $value;
		}
		$items = array_values( array_filter( array_map( static fn( $f ) => is_string( $f ) ? trim( $f ) : '', $list ), 'strlen' ) );
		if ( ! $items ) {
			return $value;
		}
		$clean = array_values( array_column( self::clean_feed( array_map( static fn( $f ) => [ 'type' => 'paragraph', 'text' => $f ], $items ) ), 'text' ) );
		return is_string( $value ) ? wp_json_encode( $clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : $clean;
	}

	/**
	 * A distributor's description blocks, cleaned like a maker page's: no first-person maker voice, calls to
	 * action or shop chrome. Feed text has no page chrome, so when the cleaner names nothing it removed, its
	 * silent page heuristics don't get to drop the text (Plugivery 4542's whole description went that way).
	 */
	public static function clean_feed( array $blocks ): array {
		if ( ! $blocks ) {
			return [];
		}
		$r = self::clean_blocks( $blocks );
		return empty( $r['removed'] ) ? $blocks : (array) ( $r['blocks'] ?? [] );
	}

	private static function clean_blocks( array $blocks, array $skip = [], string $page = '' ): array {
		$out     = [];
		$removed = [];
		$seen    = [];
		$len     = 0;
		$section = false; // inside a part about the vendor's store or delivery, or a heading the owner marked
		foreach ( $blocks as $i => $b ) {
			if ( $b['type'] === 'heading' && preg_match( self::STOP_HEADINGS, $b['text'] ) ) {
				break;
			}
			if ( $b['type'] === 'heading' || self::pseudo_heading( $b ) ) {
				$section = self::other_product_card( (string) ( $b['card'] ?? '' ), $page ) || (bool) preg_match( self::VENDOR_SECTIONS, $b['text'] ) || self::site_skipped( $b['text'], $skip );
				if ( $section ) {
					$removed[] = $b['text'];
					continue;
				}
			} elseif ( $section ) {
				$removed[] = $b['type'] === 'list' ? implode( ' · ', $b['items'] ) : $b['text'];
				continue;
			}
			// A list of the vendor's social profiles is site chrome (2026-10-21: 24 of 600 sampled pages, every one chrome).
			if ( $b['type'] === 'list' && self::is_pager( $b['items'] ) ) {
				$removed[] = implode( ' · ', $b['items'] );
				continue;
			}
			if ( $b['type'] === 'list' && count( preg_grep( self::SOCIAL_ITEM, array_map( 'trim', $b['items'] ) ) ) * 2 >= count( $b['items'] ) ) {
				$removed[] = implode( ' · ', $b['items'] );
				continue;
			}
			if ( $b['type'] === 'list' ) {
				$keep = [];
				foreach ( $b['items'] as $it ) {
					if ( self::is_junk( $it ) ) {
						continue;
					}
					if ( self::is_boilerplate( $it ) || self::site_skipped( $it, $skip ) || self::vendor_line( $it ) ) {
						$removed[] = $it;
					} elseif ( ! isset( $seen[ mb_strtolower( $it ) ] ) ) {
						$seen[ mb_strtolower( $it ) ] = true;
						$keep[]                       = $it;
					}
				}
				if ( ! $keep ) {
					continue;
				}
				$b = [ 'type' => 'list', 'items' => $keep, 'text' => implode( ' ', $keep ) ];
			} else {
				if ( $b['type'] === 'heading' && self::vendor_line( $b['text'] ) ) {
					$removed[] = $b['text']; // "Our Homage to the Original Sampler": the heading goes, the part under it stays
					continue;
				}
				$k = mb_strtolower( $b['text'] );
				if ( isset( $seen[ $k ] ) || ( $b['type'] !== 'heading' && self::is_junk( $b['text'] ) ) || preg_match( self::CODE_TEXT, $b['text'] ) ) {
					continue;
				}
				if ( self::is_boilerplate( $b['text'] ) || self::is_cta( $b, $blocks[ $i - 1 ] ?? null, $blocks[ $i + 1 ] ?? null ) || self::site_skipped( $b['text'], $skip ) ) {
					$removed[] = $b['text'];
					continue;
				}
				if ( $b['type'] !== 'heading' ) {
					[ $kept, $gone ] = self::without_vendor_lines( $b['text'] );
					if ( $gone ) {
						array_push( $removed, ...$gone );
						$k = mb_strtolower( $kept );
						if ( $kept === '' || isset( $seen[ $k ] ) ) {
							continue;
						}
						$b['text'] = $kept;
					}
				}
				$seen[ $k ] = true;
			}
			// A heading with nothing after it before the next heading is dropped at render time.
			$out[] = $b;
			$len  += mb_strlen( $b['text'] );
			if ( $len >= self::MAX_TEXT || count( $out ) >= self::MAX_BLOCKS ) {
				break;
			}
		}
		// Trailing headings (or headings followed only by other headings) carry no copy.
		$clean = [];
		foreach ( $out as $i => $b ) {
			if ( $b['type'] === 'heading' && ( ! isset( $out[ $i + 1 ] ) || $out[ $i + 1 ]['type'] === 'heading' ) ) {
				continue;
			}
			$clean[] = $b;
		}
		return [ 'blocks' => $clean, 'removed' => array_map( static fn( $t ) => mb_substr( $t, 0, 160 ), $removed ) ];
	}

	/**
	 * Not copy at all: embedded JSON/code, menus and selector lists flattened into one run of words
	 * ("Virtual InstrumentsAlbino-3 LegendBIT 2.0…", "Afghanistan USD $ Åland Islands EUR €…").
	 */
	private static function is_junk( string $t, bool $bullets = false ): bool {
		if ( preg_match( '/^[\[{]|"\s*:\s*["\[{\d]|&quot;|\b(function|var|const)\s*\(|=>|\{\{|<\/?(span|div)\b|data-mce-/', $t ) ) {
			return true;
		}
		$len = mb_strlen( $t );
		if ( $len > 120 && ! preg_match( '/[.!?…]/u', $t ) && ( ! $bullets || self::label_run( $t ) ) ) {
			return true; // a long run with no sentence in it is a menu or a list of labels
		}
		if ( preg_match_all( '/\p{Ll}\p{Lu}/u', $t ) >= ( $bullets ? 5 : 4 ) && preg_match_all( '/[.!?]/', $t ) < 2 ) {
			return true; // words glued together: menu items that had no spaces between their links
		}
		$letters = preg_match_all( '/\p{L}/u', $t );
		if ( $len > 80 && $letters && preg_match_all( '/\p{Lu}/u', $t ) / $letters > 0.6 ) {
			return true; // a long run in capitals is a menu ("INSTRUMENT ELECTRIC GUITAR BASS GUITAR …")
		}
		return preg_match_all( '/\b(USD|EUR|GBP|CAD|AUD|JPY)\b/', $t ) >= 3;
	}

	/**
	 * A run of labels (menu, category list) rather than a feature bullet: most words capitalised and
	 * no comma-separated list. Bullets flattened to paragraphs often have no full stop ("Extract stereo
	 * elements into lossless stems…") but read as lower-case prose; "LANDR FX Suite, LANDR Guitar, …" is a list.
	 */
	private static function label_run( string $t ): bool {
		$words = preg_split( '/\s+/u', preg_replace( '/[^\p{L}\s]/u', ' ', $t ), -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $words ) < 8 || substr_count( $t, ',' ) >= 2 ) {
			return false;
		}
		$caps = count( array_filter( $words, static fn( $w ) => (bool) preg_match( '/^\p{Lu}/u', $w ) ) );
		return $caps / count( $words ) >= 0.7;
	}

	/**
	 * A text block that is page chrome (menu, cookie banner, cart, price line) rather than product
	 * copy. For readers that have their own paragraphs, such as Enrichment's stored vendor copy.
	 */
	public static function is_chrome( string $t ): bool {
		$t = self::text( $t );
		return self::is_junk( $t, true ) || self::is_boilerplate( $t ) || self::is_cta( [ 'type' => 'paragraph', 'text' => $t ] );
	}

	/** A sentence or list item in the vendor's own voice, or one that sends the reader somewhere (VENDOR_VOICE, VENDOR_ASK). */
	private static function vendor_line( string $t ): bool {
		return (bool) preg_match( self::VENDOR_VOICE, $t ) || (bool) preg_match( self::STORE_META, $t ) || ( mb_strlen( $t ) <= self::VENDOR_ASK_MAX && ( preg_match( self::VENDOR_ASK, $t ) || preg_match( self::PRICE, $t ) ) );
	}

	/**
	 * A paragraph without its vendor lines, sentence by sentence: [ what is left ('' when nothing), the sentences
	 * taken out ]. A paragraph with none comes back unchanged.
	 * @return array{0:string,1:string[]}
	 */
	private static function without_vendor_lines( string $t ): array {
		$lead = (string) preg_replace( self::LEAD_CHROME, '', $t );
		if ( $lead !== $t ) {
			[ $kept, $gone ] = self::without_vendor_lines( trim( $lead ) );
			return [ $kept, array_merge( [ mb_substr( $t, 0, mb_strlen( $t ) - mb_strlen( $lead ) ) ], $gone ) ];
		}
		$sentences = preg_split( '/(?<=[.!?…])\s+(?=[\p{Lu}\d"“‘(])/u', $t ) ?: [ $t ];
		$gone      = array_values( array_filter( $sentences, [ __CLASS__, 'vendor_line' ] ) );
		if ( ! $gone ) {
			return [ $t, [] ];
		}
		return [ trim( implode( ' ', array_diff_key( $sentences, array_filter( $sentences, [ __CLASS__, 'vendor_line' ] ) ) ) ), $gone ];
	}

	private static function is_boilerplate( string $t ): bool {
		// Long prose that merely mentions a word ("…ships with 40 presets…") is copy, not chrome:
		// only short-ish blocks are judged by pattern. LONG_CHROME phrases mark a block at any length.
		if ( preg_match( self::LONG_CHROME, $t ) ) {
			return true;
		}
		if ( mb_strlen( $t ) > 260 ) {
			return false;
		}
		foreach ( self::BOILERPLATE as $rx ) {
			if ( preg_match( $rx, $t ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A call to action: a short button or link label, never product copy. $prev and $next are the blocks
	 * around it: a whole-line link followed by real copy is that copy's label, and a run of them is a
	 * list (Faber's included tools: "Oscilloscope", "FFT Analyzer"…), so both stay; a lone one is
	 * navigation ("Where to buy ?", "Purchase here").
	 */
	private static function is_cta( array $b, ?array $prev = null, ?array $next = null ): bool {
		$t = (string) ( $b['text'] ?? '' );
		if ( $t === '' || mb_strlen( $t ) > self::CTA_MAX ) {
			return false;
		}
		if ( preg_match( self::CTA, $t ) ) {
			return true;
		}
		$words = count( preg_split( '/\s+/u', trim( $t ) ) );
		$tail  = (bool) preg_match( self::CTA_TAIL, $t );
		$label = ( $next && $next['type'] !== 'heading' && ( ! empty( $next['link'] ) || mb_strlen( (string) $next['text'] ) >= 60 ) )
			|| ( $prev && ! empty( $prev['link'] ) );
		$link  = $b['type'] === 'paragraph' && ! empty( $b['link'] ) && ( $tail || preg_match( self::CTA_VERB, $t ) || ! $label );
		return $words <= 8 && ( $link || ( $words <= 4 && $tail ) );
	}

	/** A short line in capitals that a page styles as a heading ("ALSO ON iOS", "THU ESSENTIALS INCLUDED"). */
	private static function pseudo_heading( array $b ): bool {
		$t = (string) ( $b['text'] ?? '' );
		if ( $b['type'] !== 'paragraph' || mb_strlen( $t ) > 40 || preg_match( '/[.!?:;,]$/u', $t ) || count( preg_split( '/\s+/u', trim( $t ) ) ) > 6 ) {
			return false;
		}
		$letters = preg_match_all( '/\p{L}/u', $t );
		return $letters >= 4 && preg_match_all( '/\p{Lu}/u', $t ) / $letters >= 0.7;
	}

	/** A line the owner marked "not product copy" on this site: the same line, or one starting with a long marked line. */
	private static function site_skipped( string $t, array $skip ): bool {
		if ( ! $skip ) {
			return false;
		}
		$k = MapCheck::text_key( $t );
		if ( $k === '' ) {
			return false;
		}
		if ( isset( $skip[ $k ] ) ) {
			return true;
		}
		foreach ( $skip as $s => $_ ) {
			if ( mb_strlen( (string) $s ) >= MapCheck::TEXT_PREFIX_MIN && str_starts_with( $k, (string) $s ) ) {
				return true;
			}
		}
		return false;
	}

	/** A <p>'s text split at blank lines made of <br>s; a single <br> is a space. @return string[] */
	private static function br_paragraphs( \DOMElement $p ): array {
		$html = '';
		foreach ( $p->childNodes as $n ) {
			$html .= $p->ownerDocument->saveHTML( $n );
		}
		$html = (string) preg_replace( '#(?:<br\b[^>]*>\s*){2,}#i', "\n\n", $html );
		$html = (string) preg_replace( '#<br\b[^>]*>#i', ' ', $html );
		$out  = [];
		foreach ( preg_split( "/\n\s*\n/", wp_strip_all_tags( $html ) ) as $part ) {
			$part = self::text( $part );
			if ( $part !== '' ) {
				$out[] = $part;
			}
		}
		return $out;
	}

	/** Lines a product description doesn't keep: downloads, trials, buying, sign-in (short ones only). */
	/**
	 * A description's opening (owner 2026-10-25: descriptions repeated the product's name and opened with "Overview"):
	 * the product page already heads the description "{post_title} Overview" (Single Product template), so an opening
	 * line that only names the product, or only labels the section, says it twice. LABEL: a whole line that is only a
	 * label ("OVERVIEW", "Product Description:", "About this product"); LABEL_WORDS: the label words a name line may
	 * carry too ("Fab Four Overview", "About Fab Four"). FILLER: words that don't make a line say more than the name.
	 */
	const OPENING_LABEL = '/^(?:the\s+|product\s+)*(?:overview|summary|description|introduction|intro|about|details|menu|at a glance|in (?:short|brief)|info(?:rmation)?)(?:\s+(?:the|this|our)\s+(?:product|plug-?in|instrument|library|software))?\s*:?$/iu';
	const OPENING_LABEL_WORDS = [ 'overview', 'summary', 'description', 'introduction', 'intro', 'about', 'details', 'product', 'info', 'information', 'introducing', 'meet' ];
	const OPENING_FILLER = [ 'the', 'a', 'an', 'by', 'from', 'for', 'and', 'of', 'new', 'all' ];
	/** An id or class token naming the site's header, menu, breadcrumbs, banner or footer, as a whole name ("header-container", "nav-outer", "site-footer"; not "product-header"). */
	const CHROME_NAME = '/^(?:site|global|main|top|mobile)?[-_]?(?:header|footer|masthead|nav|navbar|navigation|menu|mega[-_]?menu|topbar|announcement[-_]?bar|promo[-_]?bar|breadcrumbs?)(?:[-_](?:container|wrapper|outer|inner|banners?|bar|area|links|items|list|dropdowns?))?$/i';
	/** The most of a page's text a CHROME_NAME element may hold and still be taken for the site's header or menu. */
	const CHROME_SHARE = 0.4;
	/** Program text a broken <script> left behind ("'+prod_obj[sku].title+'", "if(x.hasOwnProperty(…)) { list_html += …"). */
	const CODE_TEXT = '/\bhasOwnProperty\b|\+=|\w\[\w+\]\.\w|;\s*\}|^\W*\'\s*\+|\+\s*\'\W*$/u';
	/** A line that breaks off mid-phrase before the heading that finishes it ("Multi-award-winning Collection of" + "Orchestral Favorites"). */
	const SPLIT_TAIL = '/\s(?:of|the|for|to|and|with|in|on|a|an|your|our|from|by|at|into|meets)$/iu';
	/** A title-like line: short, no sentence end (a heading, or a paragraph styled as one). */
	const TITLE_LINE_MAX = 80;

	const DESC_DROP = '/^(download|get|try|start)\b.*\b(installer|demo|trial|now|here|latest version)\b|^\d+[- ]day free trial|^(buy|add to cart|shop) now\b|^(sign|log) ?in\b|^subscribe\b/i';

	/**
	 * The description to propose from an extract(): the page's own blocks, minus a heading that only
	 * repeats the product name, download/trial/buy lines, and system requirements (those go to the
	 * requirement fields, PageRequirements::fill_product()) with their heading. Owner 2026-10-08: the old
	 * reader (VendorFetcher::extract_copy) kept 472 of the Undertone MPEQ-1 page's ~1,900 characters.
	 */
	public static function description_html( array $x ): string {
		$name = mb_strtolower( self::text( (string) ( $x['name'] ?? '' ) ) );
		$out  = [];
		$skip = false;
		foreach ( self::opening( (array) ( $x['blocks'] ?? [] ), (string) ( $x['product'] ?? '' ), (string) ( $x['name'] ?? '' ) ) as $i => $b ) {
			$text = (string) ( $b['text'] ?? '' );
			if ( $b['type'] === 'heading' ) {
				$skip = (bool) preg_match( '/^(system |minimum )?requirements|^tech(nical)? specs|^compatibility|^downloads?$|^installers?$/i', $text );
				if ( $skip || ( $i === 0 && $name !== '' && ( mb_strtolower( $text ) === $name || str_contains( $name, mb_strtolower( $text ) ) ) ) ) {
					continue;
				}
				$out[] = $b;
				continue;
			}
			if ( $skip ) {
				continue; // under a requirements heading
			}
			if ( mb_strlen( $text ) < 120 && preg_match( self::DESC_DROP, $text ) ) {
				continue;
			}
			if ( $b['type'] === 'list' && self::looks_like_requirements( $text ) ) {
				continue;
			}
			$out[] = $b;
		}
		return self::render( $out );
	}

	/**
	 * A description's blocks with its opening tidied (OPENING_LABEL): a headline split over two lines is one heading
	 * again (anywhere), and before the first real copy, a title-like line that only labels the section goes, and one
	 * that only repeats the product's name: every word of it is in our product title ($product, its "(…)" qualifiers
	 * aside), or it is the page's own product name ($page_name, "Name | Site" → Name) word for word. A line that only
	 * shares words with the page's name keeps what it adds ("11-Operator FM Synthesizer" on F.'em's page).
	 */
	public static function opening( array $blocks, string $product, string $page_name = '' ): array {
		$joined = [];
		foreach ( $blocks as $b ) {
			$prev = $joined ? $joined[ count( $joined ) - 1 ] : null;
			if ( $b['type'] === 'heading' && $prev && $prev['type'] === 'paragraph' && self::title_line( $prev ) && preg_match( self::SPLIT_TAIL, (string) $prev['text'] ) ) {
				array_pop( $joined );
				$b['text'] = $prev['text'] . ' ' . $b['text'];
			}
			$joined[] = $b;
		}
		$core  = static fn( string $t ): array => array_values( array_diff( self::words( $t ), self::OPENING_LABEL_WORDS, self::OPENING_FILLER ) );
		$own   = $core( (string) preg_replace( '/\s*\([^)]*\)\s*/u', ' ', self::text( $product ) ) ); // "(Upgrade, Download)" aside
		$page  = $core( (string) preg_split( '/\s[|–—]\s|\s-\s/u', self::text( $page_name ) )[0] );
		$out  = [];
		$lead = true;
		foreach ( $joined as $b ) {
			if ( $lead && self::title_line( $b ) ) {
				$t     = self::text( (string) ( $b['text'] ?? '' ) );
				$words = $core( $t );
				$named = $words && ( ( $own && ! array_diff( $words, $own ) ) || ( $page && ! array_diff( $words, $page ) && ! array_diff( $page, $words ) ) );
				if ( preg_match( self::OPENING_LABEL, $t ) || $named ) {
					continue; // "FAB FOUR", "OVERVIEW", "Symphonic Orchestra Overview": the product page says it already
				}
			} elseif ( $lead && $b['type'] === 'list' && ! array_filter( (array) ( $b['items'] ?? [] ), static fn( $it ) => ! preg_match( self::OPENING_LABEL, self::text( (string) $it ) ) ) ) {
				continue; // a tab bar's labels ("MENU", "OVERVIEW")
			} elseif ( $b['type'] !== 'heading' ) {
				$lead = false; // the first real copy ends the opening
			}
			$out[] = $b;
		}
		return $out;
	}

	/** A heading, or a short paragraph with no sentence end that reads as one (TITLE_LINE_MAX). */
	private static function title_line( array $b ): bool {
		if ( $b['type'] === 'heading' ) {
			return true;
		}
		$t = self::text( (string) ( $b['text'] ?? '' ) );
		return $b['type'] === 'paragraph' && $t !== '' && mb_strlen( $t ) <= self::TITLE_LINE_MAX && ! preg_match( '/[.!?…]["”’)]?$/u', $t ) && count( preg_split( '/\s+/u', $t ) ) <= 10;
	}

	/** Lower-case words (letters and digits) of a line, each once. @return string[] */
	private static function words( string $t ): array {
		return array_values( array_unique( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $t ), -1, PREG_SPLIT_NO_EMPTY ) ) );
	}

	/** Three or more requirement signals: OS names, RAM, plug-in formats, CPU, bitness. */
	public static function looks_like_requirements( string $text ): bool {
		$n = 0;
		foreach ( [ '/\bmac ?os\b|\bos ?x\b|\bmac\b/i', '/\bwindows\b/i', '/\b\d+ ?GB\b/i', '/\b(AAX|VST ?3?|AU)\b/', '/\b(intel|amd|apple silicon|m1|processor|cpu)\b/i', '/\b(32|64)[- ]?bit\b/i' ] as $re ) {
			$n += preg_match( $re, $text ) ? 1 : 0;
		}
		return $n >= 3;
	}

	private static function text( string $s ): string {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $s, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	}

	private static function blocks_len( array $blocks ): int {
		return array_sum( array_map( static fn( $b ) => $b['type'] === 'heading' ? 0 : mb_strlen( $b['text'] ), $blocks ) );
	}

	/** A list that pages through something, not one that says anything: most of its items are a pager's. */
	public static function is_pager( array $items ): bool {
		$items = array_map( static fn( $i ) => trim( (string) $i ), $items );
		return count( $items ) >= 2 && count( (array) preg_grep( self::PAGER_ITEM, $items ) ) * 2 >= count( $items );
	}

	/** The page says it is a shop's category or archive, whatever its address looks like (/products/moforte/geoswam). */
	public static function is_listing_page( string $html ): bool {
		return (bool) preg_match( self::LISTING_BODY, $html );
	}

	/** heading → <h3>, paragraph → <p>, list → <ul>; every text escaped. */
	public static function render( array $blocks ): string {
		$html = [];
		foreach ( $blocks as $b ) {
			if ( $b['type'] === 'heading' ) {
				$html[] = '<h3>' . esc_html( $b['text'] ) . '</h3>';
			} elseif ( $b['type'] === 'list' ) {
				$html[] = '<ul>' . implode( '', array_map( static fn( $i ) => '<li>' . esc_html( $i ) . '</li>', $b['items'] ) ) . '</ul>';
			} else {
				$html[] = '<p>' . esc_html( $b['text'] ) . '</p>';
			}
		}
		return implode( "\n", $html );
	}

	/* ── Other fields (owner 2026-10-16: every field from the maker's page) ── */

	const MAX_FIELD_ITEMS = 30;
	const MANUAL_LINK     = '/manual|user.?guide|operation|documentation|handbook|reference/i';
	const AUDIO_FILE      = '/\.(mp3|m4a|ogg|oga|wav|flac|aac)(\?|$)/i';
	const WEIGHT_LABEL    = '/^(?:net |item |product |shipping |unit )?weight\b/i';
	const SIZE_LABEL      = '/^(?:product |item |shipping |unit |overall )?(?:dimensions?|size|measurements?)\b/i';
	const WEIGHT_RE       = '/(\d+(?:[.,]\d+)?)\s*(kg|kilograms?|g|grams?|lbs?|pounds?|oz|ounces?)\b/i';
	const SIZE_RE         = '/(\d+(?:[.,]\d+)?)\s*(?:mm|cm|in|")?\s*[x×*]\s*(\d+(?:[.,]\d+)?)\s*(?:mm|cm|in|")?\s*(?:[x×*]\s*(\d+(?:[.,]\d+)?))?\s*(mm|cm|m|in(?:ch(?:es)?)?|")/iu';

	/**
	 * What the page gives a product besides its description and pictures, as values: the feature list,
	 * videos, audio demos, manuals, the software version, and weight and size. Coverage counts them (the
	 * Content column); writing them to products waits for the owner's per-field sign-off (PRODUCT_DATA_PROGRAM
	 * §11, phase 3). A confirmed vendor map's parts are added by the caller (VendorMaps::extras()).
	 *
	 * @param array $x VendorPage::extract() of the same page (its description blocks).
	 * @return array{features:string[],videos:string[],audio:array<int,array{label:string,value:string}>,manuals:array<int,array{label:string,value:string}>,version:string,size:array{weight:?array,dims:?array,source:string}}
	 */
	public static function fields( string $html, string $page_url, array $x ): array {
		$out = [ 'features' => [], 'videos' => [], 'audio' => [], 'manuals' => [], 'version' => '', 'size' => [ 'weight' => null, 'dims' => null, 'source' => '' ] ];

		// Features: the description's lists of three or more points.
		$seen = [];
		foreach ( (array) ( $x['blocks'] ?? [] ) as $b ) {
			$items = (array) ( $b['items'] ?? [] );
			if ( ( $b['type'] ?? '' ) !== 'list' || count( $items ) < 3 ) {
				continue;
			}
			$one_word = count( array_filter( $items, static fn( $i ) => ! preg_match( '/\S\s+\S/u', trim( (string) $i ) ) ) );
			if ( $one_word * 2 > count( $items ) ) {
				continue; // a tab bar or social links ("Info, Specs", "facebook, youtube"), not features
			}
			foreach ( $items as $item ) {
				$t = self::text( (string) $item );
				if ( $t !== '' && ! isset( $seen[ mb_strtolower( $t ) ] ) && count( $out['features'] ) < self::MAX_FIELD_ITEMS ) {
					$seen[ mb_strtolower( $t ) ] = true;
					$out['features'][]           = $t;
				}
			}
		}

		// Links and embeds: attribute values, and the video keys page builders keep in their data
		// ("youtubeId", "youtube_link"); never any 11 characters in a script (NUXT_DATA__ was read as a video).
		$attrs = preg_match_all( '~\b(src|href|data-src|data-url|data-video(?:-src|-url|-id)?|data-youtube(?:-id)?|videoid)\s*=\s*["\']([^"\']{3,500})["\']~i', $html, $am, PREG_SET_ORDER ) ? array_map( static fn( $m ) => [ strtolower( $m[1] ), $m[2] ], $am ) : [];
		if ( preg_match_all( '~"(youtubeId|youtube_id|youtubeVideoId|youtube_link|youtube_url|youtubeUrl|video_url|videoUrl|href)"\s*:\s*"([^"]{11,500})"~', $html, $jm, PREG_SET_ORDER ) ) {
			foreach ( $jm as $m ) {
				$attrs[] = [ 'json-' . strtolower( $m[1] ), stripslashes( $m[2] ) ];
			}
		}
		$yt      = [];
		$vimeo   = [];
		$audio   = [];
		$players = []; // SoundCloud players embedded on the page: what the page plays (links beside them are credits)
		foreach ( $attrs as [ $name, $raw ] ) {
			$v = html_entity_decode( trim( $raw ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			$m = [];
			if ( $name === 'json-href' && ! preg_match( '~soundcloud\.com|' . trim( self::AUDIO_FILE, '/i' ) . '~i', $v ) ) {
				continue; // a builder's links are read for audio players only (its navigation links videos too)
			}
			if ( preg_match( '~(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?(?:.*&)?v=|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~', $v, $m )
				|| ( preg_match( '/id$|^videoid$/', $name ) && preg_match( '/^([A-Za-z0-9_-]{11})$/', $v, $m ) ) ) { // a bare id only where the attribute or key says it is one
				$id = class_exists( '\MMI_Product_Content_Registry' ) ? \MMI_Product_Content_Registry::youtube_id( $m[1] ) : $m[1];
				if ( $id !== '' && preg_match( '/[a-z]/', $id ) ) { // a real id is random mixed case; "NUXT_DATA__" has no lower case
					$yt[ $id ] = 'https://www.youtube.com/watch?v=' . $id;
				}
			} elseif ( preg_match( '~(?:player\.)?vimeo\.com/(?:video/)?(\d{6,12})\b~', $v, $m ) ) {
				$vimeo[ $m[1] ] = 'https://vimeo.com/' . $m[1];
			} elseif ( preg_match( '~w\.soundcloud\.com/player~i', $v ) ) {
				parse_str( (string) wp_parse_url( $v, PHP_URL_QUERY ), $q );
				$track = trim( (string) ( $q['url'] ?? '' ) ); // the whole address: a private playlist needs its secret_token
				if ( preg_match( '~^https?://api\.soundcloud\.com/(?:tracks|playlists)/(?:soundcloud:(?:tracks|playlists):)?\d+~', $track )
					|| preg_match( '~^https?://(?:www\.)?soundcloud\.com/[^/?#]+/[^?#]+~i', $track ) ) { // overloud.com plays soundcloud.com/overloud/sets/breverb-2-demo
					$players[ $track ] = [ 'label' => 'SoundCloud', 'value' => esc_url_raw( $track ) ];
				}
			} elseif ( preg_match( '~^https?://(?:www\.)?soundcloud\.com/[^/?#]+/[^?#]+~i', $v, $m ) && ! preg_match( '~/(?:followers|following|likes|reposts|tracks|albums|popular-tracks|sets)/?$~', $m[0] ) ) {
				$audio[ $m[0] ] = [ 'label' => 'SoundCloud', 'value' => $m[0] ]; // a track or set; the bare profile is the vendor's channel
			} elseif ( preg_match( self::AUDIO_FILE, $v ) ) {
				$u = self::absolute( $v, $page_url );
				if ( preg_match( '#^https?://#i', $u ) ) {
					$audio[ $u ] = [ 'label' => 'Audio demo', 'value' => $u ];
				}
			}
		}
		$out['videos'] = array_slice( array_values( $yt + $vimeo ), 0, self::MAX_FIELD_ITEMS );
		// A page with a player: what it plays. Its credit links ("overloud · TH-U" under Breverb 2's player) aren't this product's.
		$files        = array_filter( $audio, static fn( $a ) => $a['label'] !== 'SoundCloud' );
		$out['audio'] = array_slice( array_values( $players ? $players + $files : $audio ), 0, self::MAX_FIELD_ITEMS );

		// Manuals: links to a PDF or a manuals page whose address or label says manual / user guide / documentation.
		if ( preg_match_all( '~<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>~is', $html, $lm, PREG_SET_ORDER ) ) {
			$seen = [];
			foreach ( $lm as $a ) {
				$href  = html_entity_decode( $a[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				$label = self::text( wp_strip_all_tags( $a[2] ) );
				if ( ( preg_match( '/\.pdf(\?|$)/i', $href ) || preg_match( '~/manuals?(/|$)~i', $href ) ) && preg_match( self::MANUAL_LINK, $href . ' ' . $label ) ) {
					$u = self::absolute( $href, $page_url );
					if ( preg_match( '#^https?://#i', $u ) && ! isset( $seen[ $u ] ) && count( $out['manuals'] ) < self::MAX_FIELD_ITEMS ) {
						$seen[ $u ]       = true;
						$out['manuals'][] = [ 'label' => $label !== '' ? mb_substr( $label, 0, 120 ) : 'Manual', 'value' => $u ];
					}
				}
			}
		}

		// Version: the page's structured data first, then the description's own words.
		$ld  = self::json_ld_product( $html );
		$ver = (string) preg_replace( '/^v(?:ersion)?\s*/i', '', $ld['version'] );
		if ( ! preg_match( '/^\d{1,3}(?:\.\d{1,4}){0,3}[a-z]?$/i', $ver ) ) {
			$ver = '';
			// A sentence about what the product needs or its history ("requires TH-U version 1.4.26 or later", "As of version
			// 1.4.26, all cabinets in TH-U…") isn't the product's own version.
			foreach ( preg_split( '/(?<=[.!?])\s+|\n+/', (string) wp_strip_all_tags( (string) ( $x['description_html'] ?? '' ) ) ) as $sentence ) {
				if ( preg_match( '/\b(?:version|v)\s?(\d{1,2}(?:\.\d{1,3}){1,2})\b/i', $sentence, $m ) && ! preg_match( '/requir|compatib|or (?:later|higher|newer|above)|minimum|at least|needs?\b|\bas of\b|\bsince\b|starting (?:with|from|in)|introduced in|added in|updated (?:in|to)|upgrade/i', $sentence ) ) {
					$ver = $m[1];
					break;
				}
			}
		}
		$out['version'] = $ver;

		// Weight and size: structured data, else a specifications table or "Weight: 1.2 kg" lines.
		$out['size'] = self::size_from_ld( $ld['size'] );
		if ( ! $out['size']['weight'] && ! $out['size']['dims'] ) {
			$out['size'] = self::size_from_specs( $html );
		}
		return $out;
	}

	/** JSON-LD weight/width/height/depth → the fields() size shape. */
	private static function size_from_ld( array $ld ): array {
		$out   = [ 'weight' => null, 'dims' => null, 'source' => '' ];
		$value = static function ( $q ): array {
			if ( is_array( $q ) ) {
				$n    = (float) str_replace( ',', '.', (string) ( $q['value'] ?? '' ) );
				$unit = strtolower( (string) ( $q['unitText'] ?? ( $q['unitCode'] ?? '' ) ) );
				return [ $n, [ 'kgm' => 'kg', 'grm' => 'g', 'lbr' => 'lbs', 'onz' => 'oz', 'mmt' => 'mm', 'cmt' => 'cm', 'inh' => 'in', 'mtr' => 'm' ][ $unit ] ?? $unit ];
			}
			return is_scalar( $q ) && preg_match( '/(\d+(?:[.,]\d+)?)\s*([a-z"]+)/i', (string) $q, $m ) ? [ (float) str_replace( ',', '.', $m[1] ), strtolower( $m[2] ) ] : [ 0.0, '' ];
		};
		if ( isset( $ld['weight'] ) ) {
			[ $n, $u ] = $value( $ld['weight'] );
			if ( $n > 0 && $u !== '' ) {
				$out['weight'] = [ $n, self::unit( $u ) ];
			}
		}
		$d = [];
		foreach ( [ 'depth', 'width', 'height' ] as $k ) {
			if ( isset( $ld[ $k ] ) ) {
				$d[] = $value( $ld[ $k ] );
			}
		}
		if ( count( $d ) === 3 && $d[0][0] > 0 && $d[1][0] > 0 && $d[2][0] > 0 && $d[0][1] !== '' ) {
			$out['dims'] = [ $d[0][0], $d[1][0], $d[2][0], self::unit( $d[0][1] ) ];
		}
		$out['source'] = $out['weight'] || $out['dims'] ? 'json_ld' : '';
		return $out;
	}

	/** A specifications table (th/td, dt/dd) or "Label: value" lines → the fields() size shape. */
	private static function size_from_specs( string $html ): array {
		$out  = [ 'weight' => null, 'dims' => null, 'source' => '' ];
		$rows = [];
		if ( preg_match_all( '~<(?:th|td|dt)[^>]*>(.*?)</(?:th|td|dt)>\s*<(?:td|dd)[^>]*>(.*?)</(?:td|dd)>~is', $html, $tm, PREG_SET_ORDER ) ) {
			foreach ( $tm as $t ) {
				$rows[] = [ self::text( wp_strip_all_tags( $t[1] ) ), self::text( wp_strip_all_tags( $t[2] ) ) ];
			}
		}
		if ( preg_match_all( '~>\s*([A-Za-z ()]{4,40}?)\s*:\s*([^<]{2,80})<~', $html, $lm, PREG_SET_ORDER ) ) {
			foreach ( $lm as $l ) {
				$rows[] = [ self::text( $l[1] ), self::text( $l[2] ) ];
			}
		}
		$num = static fn( $v ) => ( $v === '' || $v === null ) ? null : (float) str_replace( ',', '.', (string) $v );
		foreach ( $rows as [ $label, $value ] ) {
			if ( ! $out['weight'] && preg_match( self::WEIGHT_LABEL, $label ) && preg_match( self::WEIGHT_RE, $value, $m ) ) {
				$out['weight'] = [ (float) str_replace( ',', '.', $m[1] ), self::unit( $m[2] ) ];
			} elseif ( ! $out['dims'] && preg_match( self::SIZE_LABEL, $label ) && preg_match( self::SIZE_RE, $value, $m ) ) {
				$out['dims'] = [ $num( $m[1] ), $num( $m[2] ), $num( $m[3] ?? '' ), self::unit( $m[4] ) ];
			}
		}
		$out['source'] = $out['weight'] || $out['dims'] ? 'specs' : '';
		return $out;
	}

	/** kg / g / lbs / oz / mm / cm / m / in, from the many ways a page writes them. */
	private static function unit( string $u ): string {
		$u   = strtolower( trim( $u ) );
		$map = [ 'kilogram' => 'kg', 'kilograms' => 'kg', 'gram' => 'g', 'grams' => 'g', 'lb' => 'lbs', 'pound' => 'lbs', 'pounds' => 'lbs', 'ounce' => 'oz', 'ounces' => 'oz', 'inch' => 'in', 'inches' => 'in', '"' => 'in' ];
		return $map[ $u ] ?? $u;
	}

	private static function absolute( string $u, string $base ): string {
		if ( $u === '' ) {
			return '';
		}
		if ( strpos( $u, '//' ) === 0 ) {
			return 'https:' . $u;
		}
		if ( preg_match( '#^https?://#i', $u ) ) {
			return $u;
		}
		$p = wp_parse_url( $base );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$root = ( $p['scheme'] ?? 'https' ) . '://' . $p['host'];
		if ( $u[0] === '/' ) {
			return $root . $u;
		}
		$dir  = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
		$path = [];
		foreach ( explode( '/', $dir . $u ) as $seg ) {
			if ( $seg === '..' ) {
				array_pop( $path );
			} elseif ( $seg !== '.' ) {
				$path[] = $seg;
			}
		}
		return $root . implode( '/', $path ); // "../images/large/x.jpg" resolved, not left in the URL
	}
}
