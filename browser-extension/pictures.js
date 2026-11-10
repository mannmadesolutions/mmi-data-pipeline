/**
 * MMI Product Data: pick pictures and videos on the maker's page for one product (owner 2026-11-01: pictures
 * picked on the page go straight to the product). Opened from the panel's "Pick pictures and videos".
 *
 * Hover a picture to outline it; click it to pick it, click again to drop it. Clicks on the page do nothing else
 * while picking, so a gallery link doesn't navigate. The biggest copy the page offers is taken (a zoom link, a
 * data-zoom attribute, the largest srcset entry). The page's YouTube videos are listed to tick. Add to the product
 * sends the picks with the page as drawn; the server queues the pictures for the picture runner's normal merge
 * (added, never replacing; no duplicates; vetoes apply) and adds the videos the product doesn't have.
 *
 * The picker never refuses a click (owner: the person is in charge of picks); a small picture is only labelled.
 *
 * Galleries (owner 2026-11-02: "select all images within a container"): a click on a picture also finds the gallery
 * around it (the nearest box holding two or more pictures); "Pick all N" takes them all, Wider / Narrower step to the
 * next box out or in, and Shift-click picks a picture's whole gallery at once. What the product has (owner: "show which
 * images the current product already has"): on opening, the page's pictures are checked against the product's by the
 * picture merge's own rules (/ext/pictures op check, fingerprints, never Claude) and labelled on the page: Has it,
 * Maybe has it, New, Sharper copy, Vetoed.
 *
 * Owner's testing (2026-11-02, soundiron's Jewel Bandolero): labels floated over a carousel's hidden slides, logos were
 * offered, Pick all took 100 px thumbnails, and nothing unpicked a gallery. So: only pictures a person can see are
 * labelled, header / footer pictures, logos and icons aren't offered, a shop's thumbnail address is turned into its
 * full-size picture (Shopify, WordPress) and a picture shown twice (slide and thumbnail) counts once, each pick shows
 * its real size, and a gallery's buttons are "Pick the N new" (never the ones the product has or that are vetoed),
 * "Also the N maybe" and "Unpick all N", on the bar and in a chip on the gallery as the pointer moves over it. A maybe
 * in the tray can be settled at once: "Same picture" vetoes it for the product, so the runner never compares it.
 *
 * A layout's pictures (plan §13 S2b): on a page of one of the site's layouts, the gallery can be saved as that
 * layout's Pictures part ("Use this gallery for layout C"), through the live mapper's route (map-select.js makes its
 * selector), so every page built like this one reads its gallery; the panel then says what that writes to the
 * layout's products, with Write.
 *
 * Audio demos (owner 2026-11-02: "include SoundCloud links/data"): the page's SoundCloud players and track or set links
 * are listed to tick, like YouTube videos; the server names them by SoundCloud's oEmbed and adds the ones the product
 * doesn't have. Opened from the panel's Videos or Audio demos row, the picker opens on that list.
 */
( function () {
	'use strict';

	if ( window.__mmiExtPicker ) {
		window.__mmiExtPicker.show();
		return;
	}

	const HOST_TAG = 'mmi-product-data-picker';
	const HOLDER = { climb: 4, words: 60 }; // VendorPage::in_video()
	const OTHER_CLIMB = 10; // VendorPage::other_product()
	const OTHER_PRODUCTS = /upsell|cross-?sell|related|recommend|also-?(?:like|bought|viewed)|you-?may|product[-_]?card|similar-?products/i; // VendorPage::OTHER_PRODUCTS
	const LIMITS = { picks: 40, boxes: 60, small: 300, thumbs: 120, side: 60, natural: 200, levels: 10, check: 60 };
	const YT_ID = /(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])/;
	const YT_ATTRS = '[data-video-id], [data-videoid], [data-youtube], [data-youtube-id], [data-yt-id], [data-video-url], [data-video-src]';
	const POSTER = /\/preview_images\/|(?:hq|mq|sd|maxres)default|ytimg\.com|img\.youtube\.com|vimeocdn|vumbnail/i; // VendorPage::IMAGE_SKIP
	const VIDEO_ATTR = /^data-(?:video|youtube|yt-|vimeo)/i; // VendorPage::VIDEO_ATTR
	const VIDEO_ADDR = /youtube(?:-nocookie)?\.com\/|youtu\.be\/|vimeo\.com\/|wistia\.|\.mp4(?:[?#]|$)/i;
	const SC_API = /^https?:\/\/api\.soundcloud\.com\/(tracks|playlists)\/(?:soundcloud:(?:tracks|playlists):)?(\d+)/i; // SoundCloud::API_RE
	const SC_PAGE = /^https?:\/\/(?:www\.|m\.)?soundcloud\.com\/[^/?#]+\/[^?#]+/i; // SoundCloud::PAGE_RE
	const SC_LISTS = /\/(?:followers|following|likes|reposts|tracks|albums|popular-tracks|sets)\/?$/;
	const LOGO_RE = /logo|icon|sprite|badge|payment|avatar|flag|favicon/i; // live-mapper.js LOGO_RE
	const CHROME_AREAS = 'header, nav, footer, [role="banner"], [role="navigation"], [role="contentinfo"]';
	const OVERLAY_RE = /cookie|consent|usercentrics|onetrust|didomi|cookiebot|gdpr|\bcmp\b|klaviyo|needsclick|privy|justuno|optinmonster|omnisend|wisepops|newsletter/i; // live-mapper.js OVERLAY_RE
	const MESSAGES = {
		title: 'Pick pictures for %t',
		hint: 'Click pictures on the page to pick them; click again to drop one. The biggest copy the page offers is taken.',
		none: 'Nothing picked yet.',
		picked: '%n picture(s) picked',
		small: '%w px wide',
		videos: 'YouTube videos on this page',
		videosN: 'YouTube videos on this page (%n)%c',
		videosChosen: ' · %n ticked',
		noVideos: 'No YouTube videos found on this page.',
		titleVideos: 'Pick videos and audio demos for %t',
		audioN: 'SoundCloud audio demos on this page (%n)%c',
		noAudio: 'No SoundCloud players or links found on this page.',
		audioPlayer: 'Player: %s',
		send: 'Add to the product',
		sending: 'Sending…',
		cancel: 'Cancel',
		remove: 'Drop this picture',
		limit: 'At most %n pictures at a time.',
		overlay: 'That picture is in a sign-up or cookie pop-up: drop it if it isn’t the product’s, and close the pop-up on the page.',
		gallery: 'Its gallery: %n pictures (outlined)%k.',
		galleryKinds: ': %new% new, %have% it has, %maybe% maybe',
		pickAll: 'Pick all %n',
		pickNew: 'Pick the %n new',
		pickMaybe: 'Also the %n maybe',
		unpickAll: 'Unpick all %n',
		forLayout: 'Use this gallery for layout %l (%p pages)',
		forLayoutTitle: 'Every page built like this one reads its pictures from the same gallery; the panel then says what that writes to their products',
		layoutSaving: 'Saving the gallery for layout %l…',
		layoutSaved: 'Saved: layout %l reads its pictures from this gallery, on %f of its %o stored pages. The panel’s Layout %l section says what it writes to their products.',
		layoutTested: 'Checked, nothing saved: the gallery reads pictures on %f of layout %l’s %o stored pages.',
		noSelector: 'This gallery has nothing that names it across pages: pick it with “Pick a part on this page” instead.',
		chip: 'Gallery · %n pictures',
		hasIt: 'The product has this picture already: adding it changes nothing.',
		isVetoed: 'You vetoed this picture for the product: it won’t be added.',
		same: 'Same picture',
		sameTitle: 'It is a picture the product has: keep it off the product (a veto), so the picture runner never compares it',
		sending2: 'Adding: %new% new%maybe%%have%.',
		sendMaybe: ', %n maybe (the picture runner compares it closely)',
		sendHave: ', %n it has already (nothing to add)',
		failed: 'couldn’t load',
		wider: 'Wider',
		narrower: 'Narrower',
		checking: 'Checking which of the page’s pictures the product has…',
		checked: 'On the page: %have% the product has, %maybe% it may have, %new% new%vetoed%.',
		vetoedN: ', %n% vetoed',
		isVideo: 'That’s a video’s thumbnail, so the video is ticked below, not the picture.',
		whichVideo: 'That’s a video’s thumbnail, not a picture. Tick the video below.',
		kinds: { video: 'Video', other: 'Other product', have: 'Has it', maybe: 'Maybe has it', new: 'New', sharper: 'Sharper copy', vetoed: 'Vetoed', duplicate: 'Has it' },
		wall: 'This is the site’s bot check, not its page, so nothing was sent.',
		reloaded: 'The extension was reloaded. Reload this page, then try again.',
	};
	const COLORS = { hover: '#1d2327', pick: '#2271b1' };
	const CSS = `
		:host { all: initial; }
		* { box-sizing: border-box; }
		.layer { position: fixed; inset: 0; pointer-events: none; z-index: 2147483646; }
		.box { position: fixed; left: 0; top: 0; width: var(--w); height: var(--h); transform: translate(var(--x), var(--y)); border: 3px solid var(--c); border-radius: 2px; }
		.box.hover { border-width: 2px; border-style: dashed; background: rgba(34,113,177,.08); }
		.box.gallery { border-width: 2px; border-style: dashed; }
		.tag { position: fixed; left: 0; top: 0; transform: translate(var(--x), var(--y)); padding: 1px 6px; border-radius: 3px; font: 600 11px/16px -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; color: #fff; background: #50575e; white-space: nowrap; }
		.tag.video, .tag.other { background: #1d2327; } .tag.have, .tag.duplicate { background: #00a32a; } .tag.maybe { background: #996800; } .tag.new, .tag.sharper { background: #2271b1; } .tag.vetoed { background: #d63638; }
		.pick .k { display: block; font-size: 11px; text-align: center; color: #50575e; }
		.gal { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
		.chip { position: fixed; left: 0; top: 0; transform: translate(var(--x), var(--y)); display: flex; align-items: center; gap: 6px; padding: 4px 6px 4px 10px; pointer-events: auto; z-index: 2147483647; background: #1d2327; color: #fff; border-radius: 16px; box-shadow: 0 4px 14px rgba(0,0,0,.3); font: 600 12px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; white-space: nowrap; }
		.chip button { min-height: 24px; padding: 0 10px; border: 0; border-radius: 12px; background: #fff; color: #1d2327; font: inherit; cursor: pointer; }
		.chip button.pr { background: #2271b1; color: #fff; }
		.pick .same { display: block; width: 100%; margin-top: 2px; min-height: 20px; padding: 0; border: 1px solid #996800; border-radius: 3px; background: #fff; color: #996800; font-size: 11px; cursor: pointer; }
		.box[hidden], [hidden] { display: none !important; }
		.bar { position: fixed; left: 12px; right: 12px; bottom: 12px; max-height: 46vh; display: flex; flex-direction: column; gap: 8px; padding: 12px 14px; pointer-events: auto; z-index: 2147483647; background: #fff; color: #1d2327; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 8px 28px rgba(0,0,0,.22); font: 13px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
		.top { display: flex; align-items: center; gap: 10px; }
		.top b { flex: 1; font-size: 14px; overflow-wrap: anywhere; }
		.hint { color: #50575e; font-size: 12px; }
		.scroll { overflow-y: auto; display: grid; gap: 8px; }
		.picks { display: flex; flex-wrap: wrap; gap: 6px; }
		.pick { position: relative; width: 76px; }
		.pick img { width: 76px; height: 76px; object-fit: contain; background: #fff; border: 1px solid #dcdcde; border-radius: 3px; display: block; }
		.pick .x { position: absolute; top: 2px; right: 2px; width: 20px; height: 20px; border: 0; border-radius: 50%; background: rgba(29,35,39,.8); color: #fff; font-size: 13px; line-height: 20px; cursor: pointer; padding: 0; }
		.pick .w { display: block; font-size: 11px; color: #996800; text-align: center; }
		summary { cursor: pointer; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
		details .vids { margin-top: 8px; }
		h3 { margin: 4px 0 0; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
		.vids { display: flex; flex-wrap: wrap; gap: 8px; }
		.vid { display: flex; align-items: center; gap: 6px; padding: 4px 8px 4px 4px; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; font-size: 12px; }
		.vid img { width: 64px; height: 36px; object-fit: cover; border-radius: 2px; }
		.muted { color: #646970; font-size: 12px; }
		.n { padding: 6px 9px; border-left: 4px solid #dba617; background: #fcf9e8; font-size: 12.5px; }
		.acts { display: flex; gap: 8px; }
		button.b { min-height: 30px; padding: 0 12px; border: 1px solid #2271b1; border-radius: 4px; background: #fff; color: #2271b1; font: inherit; font-weight: 500; cursor: pointer; }
		button.b.pr { background: #2271b1; color: #fff; }
		button.b:disabled { opacity: .6; cursor: default; }
		button:focus-visible, input:focus-visible { outline: 2px solid #2271b1; outline-offset: 1px; }
	`;

	const pickFor = window.__mmiExtPickFor || {};
	const state = { picks: [], hover: null, videos: [], chosen: new Set(), note: '', sending: false, galleries: [], gi: 0, kinds: new Map(), tags: [], summary: '', videosOpen: false, hoverGal: null, layout: null, saving: false, audio: [], heard: new Set(), audioOpen: false };

	/**
	 * A picture worth offering: drawn at a usable size (or large behind a thumbnail), not in the site's header, menus or
	 * footer or a sign-up or cookie pop-up, not a logo, icon or drawing (svg); never one of ours.
	 */
	const offered = ( img ) => {
		const r = img.getBoundingClientRect();
		const src = img.currentSrc || img.src || '';
		return ( r.width >= LIMITS.side || r.height >= LIMITS.side || img.naturalWidth >= LIMITS.natural )
			&& ! img.closest( CHROME_AREAS ) && ! /\.svg(?:[?#]|$)/i.test( src )
			&& ! LOGO_RE.test( `${ src } ${ img.alt || '' } ${ typeof img.className === 'string' ? img.className : '' }` )
			&& ! ( typeof host !== 'undefined' && host.contains( img ) ) && ! inOverlay( img );
	};

	/**
	 * A click-to-play video's picture (owner 2026-11-06: not a product picture): its poster's address, or an element around
	 * it, alone with it, that plays one. { id }: the YouTube video, when the page says which. null: an ordinary picture.
	 */
	function videoThumb( img ) {
		const src = img.currentSrc || img.src || '';
		const named = /\/vi(?:_webp)?\/([A-Za-z0-9_-]{11})\//.exec( src );
		let id = named ? named[ 1 ] : '';
		let plays = POSTER.test( src );
		let up = img.parentElement;
		for ( let i = 0; i < HOLDER.climb && up; i++, up = up.parentElement ) {
			if ( up.querySelectorAll( 'img' ).length > 1 || up.textContent.trim().length > HOLDER.words ) {
				break;
			}
			Array.from( up.attributes ).forEach( ( a ) => {
				const addr = [ 'href', 'data-href', 'data-src', 'data-url' ].includes( a.name ) && VIDEO_ADDR.test( a.value );
				if ( addr || ( a.value && VIDEO_ATTR.test( a.name ) ) || ( a.name === 'data-media-type' && /video/i.test( a.value ) ) ) {
					plays = true;
					const m = YT_ID.exec( a.value );
					id = id || ( m ? m[ 1 ] : /^[A-Za-z0-9_-]{11}$/.test( a.value ) ? a.value : '' );
				}
			} );
		}
		return plays ? { id } : null;
	}

	/** This picture stands for another product (VendorPage::other_product()): labelled and left out of Pick all, and still a person's to click. */
	function otherProduct( img ) {
		const trimmed = ( p ) => p.replace( /\/+$/, '' );
		const last = ( p ) => p.split( '/' ).pop();
		const here = trimmed( location.pathname );
		let up = img.parentElement;
		for ( let i = 0; i < OTHER_CLIMB && up && ! /^(BODY|HTML|MAIN)$/.test( up.nodeName ); i++, up = up.parentElement ) {
			if ( OTHER_PRODUCTS.test( `${ typeof up.className === 'string' ? up.className : '' } ${ up.id }` ) ) {
				return true;
			}
			const href = up.nodeName === 'A' ? ( up.getAttribute( 'href' ) || '' ).trim() : '';
			if ( ! href || href[ 0 ] === '#' || /\.(?:jpe?g|png|webp|gif|avif)(?:[?#]|$)|^(?:mailto|tel|javascript):/i.test( href ) ) {
				continue;
			}
			let to = null;
			try {
				to = new URL( href, location.href );
			} catch ( e ) {
				continue;
			}
			const path = trimmed( to.pathname );
			if ( path && to.hostname.replace( /^www\./, '' ) === location.hostname.replace( /^www\./, '' ) && last( path ) !== last( here ) && ! `${ here }/`.startsWith( `${ path }/` ) && ! `${ path }/`.startsWith( `${ here }/` ) ) {
				return true;
			}
		}
		return false;
	}

	/** The pictures in a box, one per picture (a slide and its thumbnail are one): [{ img, src, key }]. */
	function picturesIn( box ) {
		const out = new Map();
		Array.from( box.querySelectorAll( 'img' ) ).filter( ( img ) => offered( img ) && ! videoThumb( img ) && ! otherProduct( img ) ).forEach( ( img ) => {
			const src = bestSrc( img );
			const key = keyOf( src );
			if ( src && ( ! out.has( key ) || ( ! shown( out.get( key ).img ) && shown( img ) ) ) ) {
				out.set( key, { img, src, key } );
			}
		} );
		return [ ...out.values() ];
	}

	/** The boxes around a picture that hold more pictures, nearest first: its gallery, then wider ones. */
	const galleryMemo = new WeakMap();
	function galleriesOf( img ) {
		if ( galleryMemo.has( img ) ) {
			return galleryMemo.get( img );
		}
		const out = [];
		let n = 1;
		for ( let e = img.parentElement, i = 0; e && e !== document.body && e !== document.documentElement && i < LIMITS.levels; e = e.parentElement, i++ ) {
			const items = picturesIn( e );
			if ( items.length > n ) {
				out.push( { el: e, items } );
				n = items.length;
			}
		}
		galleryMemo.set( img, out );
		return out;
	}

	/**
	 * Shown to a person: in the window, visible, and not clipped away by a box that hides its overflow (a carousel's
	 * other slides sit beside it, hidden). Measured a few times a second, not on every frame.
	 */
	let seen = new WeakMap();
	let seenAt = 0;
	function shown( img ) {
		if ( seen.has( img ) ) {
			return seen.get( img );
		}
		const r = img.getBoundingClientRect();
		let ok = r.width > 2 && r.height > 2 && r.bottom > 0 && r.top < window.innerHeight && r.right > 0 && r.left < window.innerWidth
			&& ( ! img.checkVisibility || img.checkVisibility( { checkOpacity: true, checkVisibilityCSS: true } ) );
		for ( let e = img.parentElement; ok && e && e !== document.body; e = e.parentElement ) {
			const cs = window.getComputedStyle( e );
			if ( cs.overflowX !== 'visible' || cs.overflowY !== 'visible' ) {
				const c = e.getBoundingClientRect();
				ok = r.right > c.left + 1 && r.left < c.right - 1 && r.bottom > c.top + 1 && r.top < c.bottom - 1;
			}
		}
		seen.set( img, ok );
		return ok;
	}

	/** What the check said of a picture: new (also unchecked), have, maybe, sharper, vetoed, other (a choice bundle's card). */
	const kindOf = ( src ) => {
		const k = state.kinds.get( src ) || 'new';
		return k === 'duplicate' ? 'have' : k;
	};
	const picked = ( key ) => state.picks.some( ( p ) => p.key === key );

	/** A gallery's pictures by what the product has of them. */
	function sortOut( items ) {
		const by = { new: [], have: [], maybe: [], vetoed: [], other: [] }; // other: never in "Pick the N new"; still a person's to click
		items.forEach( ( it ) => {
			const k = kindOf( it.src );
			( by[ k === 'sharper' ? 'new' : k ] || by.new ).push( it );
		} );
		return by;
	}

	/** Unpick these pictures. */
	function unpickAll( items ) {
		const keys = new Set( items.map( ( it ) => it.key ) );
		state.picks = state.picks.filter( ( p ) => ! keys.has( p.key ) );
	}

	/** A pick's real size: its full-size copy loaded once (the thumbnail on the page says nothing of it). */
	function measure( p ) {
		const im = new Image();
		im.referrerPolicy = 'no-referrer';
		im.onload = () => {
			p.w = im.naturalWidth;
			p.h = im.naturalHeight;
			render();
		};
		im.onerror = () => {
			p.failed = true;
			render();
		};
		im.src = p.src;
	}

	/** A fixed box over the page that names itself as a sign-up or cookie pop-up holds this picture. */
	function inOverlay( img ) {
		for ( let e = img.parentElement; e && e !== document.body; e = e.parentElement ) {
			if ( OVERLAY_RE.test( `${ e.id || '' } ${ typeof e.className === 'string' ? e.className : '' }` ) && window.getComputedStyle( e ).position === 'fixed' ) {
				return true;
			}
		}
		return false;
	}

	/** Pick these pictures (the ones not picked yet), up to the limit. */
	function pickAll( items ) {
		for ( const it of items ) {
			if ( state.picks.length >= LIMITS.picks ) {
				state.note = MESSAGES.limit.replace( '%n', LIMITS.picks );
				break;
			}
			if ( ! picked( it.key ) ) {
				const p = { img: it.img, src: it.src, key: it.key, w: 0 };
				state.picks.push( p );
				measure( p );
			}
		}
	}

	/* ── Which copy of a picture to take ───────────────────────────────── */

	// A picture's full-size address and copy: map-select.js, one set of rules with the mapper and the server (Enrichment::full_size()).
	const SEL = window.mmptMapSelect;
	const { bestSrc, keyOf } = SEL;

	/**
	 * The picture under the pointer, even when a gallery lays a link or a see-through backdrop over it: a picture anywhere
	 * in the stack first, else a box holding just one.
	 */
	function pictureAt( x, y ) {
		const stack = document.elementsFromPoint( x, y ).filter( ( e ) => e !== host && ! host.contains( e ) ).slice( 0, 12 );
		const img = stack.find( ( e ) => e.nodeName === 'IMG' );
		if ( img ) {
			return img;
		}
		for ( const e of stack ) {
			const inner = e.querySelectorAll ? e.querySelectorAll( 'img' ) : [];
			if ( inner.length === 1 ) {
				return inner[ 0 ];
			}
		}
		return null;
	}

	/** The page's YouTube videos: embeds, links and the ids players keep in data attributes. */
	/** An embed's address: src, or the lazy-loading copy a page keeps until it scrolls into view (soundiron). */
	const embedSrc = ( e ) => e.getAttribute( 'src' ) || e.getAttribute( 'data-src' ) || e.getAttribute( 'data-lazy-src' ) || '';

	function videosOnPage() {
		const ids = new Set();
		document.querySelectorAll( 'iframe[src], iframe[data-src], iframe[data-lazy-src], a[href]' ).forEach( ( e ) => {
			const m = YT_ID.exec( e.nodeName === 'A' ? e.getAttribute( 'href' ) || '' : embedSrc( e ) );
			if ( m ) {
				ids.add( m[ 1 ] );
			}
		} );
		document.querySelectorAll( YT_ATTRS ).forEach( ( e ) => {
			Array.from( e.attributes ).forEach( ( a ) => {
				const m = YT_ID.exec( a.value );
				if ( /^data-(video-?(id|url|src)|youtube(-id)?|yt-id)$/i.test( a.name ) && ( m || /^[A-Za-z0-9_-]{11}$/.test( a.value ) ) ) {
					ids.add( m ? m[ 1 ] : a.value );
				}
			} );
		} );
		return Array.from( ids );
	}

	/** The page's SoundCloud tracks and sets, one each: players (their api address) and links to a track or set. */
	function audioOnPage() {
		const out = new Map(); // address → what it is
		document.querySelectorAll( 'iframe[src*="soundcloud.com"], iframe[data-src*="soundcloud.com"], iframe[data-lazy-src*="soundcloud.com"]' ).forEach( ( e ) => {
			try {
				const u = new URL( embedSrc( e ), location.href ).searchParams.get( 'url' ) || '';
				const m = SC_API.exec( u );
				if ( m ) {
					out.set( `https://api.soundcloud.com/${ m[ 1 ].toLowerCase() }/${ m[ 2 ] }`, MESSAGES.audioPlayer.replace( '%s', e.getAttribute( 'title' ) || `${ m[ 1 ].replace( /s$/, '' ) } ${ m[ 2 ] }` ) );
				}
			} catch ( err ) {}
		} );
		document.querySelectorAll( 'a[href*="soundcloud.com/"]' ).forEach( ( a ) => {
			const m = SC_PAGE.exec( a.href );
			if ( m && ! SC_LISTS.test( m[ 0 ] ) && ! a.closest( CHROME_AREAS ) ) {
				const u = m[ 0 ].split( /[?#]/ )[ 0 ];
				out.has( u ) || out.set( u, new URL( u ).pathname.slice( 1 ) );
			}
		} );
		return [ ...out ].map( ( [ url, name ] ) => ( { url, name } ) );
	}

	/* ── Drawing ───────────────────────────────────────────────────────── */

	const host = document.createElement( HOST_TAG );
	host.setAttribute( 'style', 'all: initial;' );
	const root = host.attachShadow( { mode: 'closed' } );
	const el = ( tag, cls, text ) => {
		const e = document.createElement( tag );
		if ( cls ) {
			e.className = cls;
		}
		if ( text !== undefined ) {
			e.textContent = text;
		}
		return e;
	};
	const layer = el( 'div', 'layer' );
	const chip = el( 'div', 'chip' );
	chip.hidden = true;
	const bar = el( 'div', 'bar' );
	bar.setAttribute( 'role', 'dialog' );
	bar.setAttribute( 'aria-label', MESSAGES.title.replace( '%t', pickFor.title || '' ) );
	root.append( el( 'style', '', CSS ), layer, chip, bar );
	document.documentElement.appendChild( host );

	const pool = [];
	const tags = [];
	function frame() {
		if ( ! host.isConnected ) {
			return;
		}
		if ( performance.now() - seenAt > 300 ) { // shown() measured again: the page scrolls, carousels move
			seen = new WeakMap();
			seenAt = performance.now();
		}
		const list = state.picks.filter( ( p ) => shown( p.img ) ).map( ( p ) => [ p.img, 'box', COLORS.pick ] );
		if ( state.hover && ! state.picks.some( ( p ) => p.img === state.hover ) ) {
			list.push( [ state.hover, 'box hover', COLORS.hover ] );
		}
		const gal = state.galleries[ state.gi ];
		if ( gal ) {
			list.push( [ gal.el, 'box gallery', COLORS.pick ] );
		}
		if ( state.hoverGal && state.hoverGal !== gal ) {
			list.push( [ state.hoverGal.el, 'box gallery', COLORS.hover ] );
		}
		placeChip();
		while ( tags.length < Math.min( state.tags.length, LIMITS.check ) ) {
			const t = el( 'div', 'tag' );
			tags.push( t );
			layer.append( t );
		}
		tags.forEach( ( t, i ) => {
			const item = state.tags[ i ];
			const r = item && item[ 0 ].isConnected && shown( item[ 0 ] ) ? item[ 0 ].getBoundingClientRect() : null;
			if ( ! r ) {
				t.hidden = true;
				return;
			}
			t.hidden = false;
			t.className = `tag ${ item[ 1 ] }`;
			t.textContent = MESSAGES.kinds[ item[ 1 ] ] || item[ 1 ];
			t.style.setProperty( '--x', `${ r.left + 3 }px` );
			t.style.setProperty( '--y', `${ r.top + 3 }px` );
		} );
		while ( pool.length < Math.min( list.length, LIMITS.boxes ) ) {
			const b = el( 'div', 'box' );
			pool.push( b );
			layer.append( b );
		}
		pool.forEach( ( b, i ) => {
			const item = list[ i ];
			const r = item && item[ 0 ].isConnected ? item[ 0 ].getBoundingClientRect() : null;
			if ( ! r || ( ! r.width && ! r.height ) ) {
				b.hidden = true;
				return;
			}
			b.hidden = false;
			b.className = item[ 1 ];
			b.style.setProperty( '--x', `${ r.left }px` );
			b.style.setProperty( '--y', `${ r.top }px` );
			b.style.setProperty( '--w', `${ r.width }px` );
			b.style.setProperty( '--h', `${ r.height }px` );
			b.style.setProperty( '--c', item[ 2 ] );
		} );
		window.requestAnimationFrame( frame );
	}

	function render() {
		const top = el( 'div', 'top' );
		top.append( el( 'b', '', ( pickFor.part === 'videos' || pickFor.part === 'audio' ? MESSAGES.titleVideos : MESSAGES.title ).replace( '%t', pickFor.title || 'this product' ) ) );
		const scroll = el( 'div', 'scroll' );
		scroll.append( el( 'div', 'hint', MESSAGES.hint ) );
		if ( state.note ) {
			scroll.append( el( 'div', 'n', state.note ) );
		}
		if ( state.summary ) {
			scroll.append( el( 'div', 'muted', state.summary ) );
		}
		const gal = state.galleries[ state.gi ];
		if ( gal ) {
			const row = el( 'div', 'gal' );
			const btn = ( text, run, off ) => {
				const b = el( 'button', 'b', text );
				b.type = 'button';
				b.disabled = !! off;
				b.addEventListener( 'click', run );
				return b;
			};
			const by = sortOut( gal.items );
			const checkedAny = gal.items.some( ( it ) => state.kinds.has( it.src ) );
			row.append( el( 'span', '', MESSAGES.gallery.replace( '%n', gal.items.length ).replace( '%k', checkedAny ? MESSAGES.galleryKinds.replace( '%new%', by.new.length ).replace( '%have%', by.have.length ).replace( '%maybe%', by.maybe.length ) : '' ) ),
				...galleryButtons( gal, btn ),
				btn( MESSAGES.wider, () => {
					state.gi++;
					render();
				}, state.gi >= state.galleries.length - 1 ),
				btn( MESSAGES.narrower, () => {
					state.gi--;
					render();
				}, state.gi <= 0 ) );
			if ( state.layout && window.mmptMapSelect ) {
				const b = btn( MESSAGES.forLayout.replace( '%l', state.layout.letter ).replace( '%p', state.layout.pages ), () => forLayout( gal ), state.saving );
				b.title = MESSAGES.forLayoutTitle;
				row.append( b );
			}
			scroll.append( row );
		}
		scroll.append( el( 'div', 'muted', state.picks.length ? MESSAGES.picked.replace( '%n', state.picks.length ) : MESSAGES.none ) );
		if ( state.picks.length ) {
			const picks = el( 'div', 'picks' );
			state.picks.forEach( ( p, i ) => {
				const d = el( 'div', 'pick' );
				const img = el( 'img' );
				img.alt = '';
				img.referrerPolicy = 'no-referrer';
				img.src = p.src;
				img.title = p.src;
				const x = el( 'button', 'x', '×' );
				x.type = 'button';
				x.title = MESSAGES.remove;
				x.setAttribute( 'aria-label', MESSAGES.remove );
				x.addEventListener( 'click', () => {
					state.picks.splice( i, 1 );
					render();
				} );
				d.append( img, x );
				if ( state.kinds.has( p.src ) ) {
					d.append( el( 'span', 'k', MESSAGES.kinds[ state.kinds.get( p.src ) ] || '' ) );
				}
				if ( p.failed ) {
					d.append( el( 'span', 'w', MESSAGES.failed ) );
				} else if ( p.w && p.w < LIMITS.small ) {
					d.append( el( 'span', 'w', MESSAGES.small.replace( '%w', p.w ) ) );
				}
				if ( kindOf( p.src ) === 'maybe' ) {
					const same = el( 'button', 'same', MESSAGES.same );
					same.type = 'button';
					same.title = MESSAGES.sameTitle;
					same.addEventListener( 'click', () => sameAs( p ) );
					d.append( same );
				}
				picks.append( d );
			} );
			scroll.append( picks );
		}
		if ( state.videos.length ) {
			// Folded until opened (owner's screenshot: 16 videos took a third of the window over the pictures)
			const fold = el( 'details' );
			fold.open = state.videosOpen;
			fold.addEventListener( 'toggle', () => {
				state.videosOpen = fold.open;
			} );
			fold.append( el( 'summary', '', MESSAGES.videosN.replace( '%n', state.videos.length ).replace( '%c', state.chosen.size ? MESSAGES.videosChosen.replace( '%n', state.chosen.size ) : '' ) ) );
			const vids = el( 'div', 'vids' );
			state.videos.forEach( ( id ) => {
				const label = el( 'label', 'vid' );
				const box = el( 'input' );
				box.type = 'checkbox';
				box.checked = state.chosen.has( id );
				box.addEventListener( 'change', () => {
					if ( box.checked ) {
						state.chosen.add( id );
					} else {
						state.chosen.delete( id );
					}
					fold.querySelector( 'summary' ).textContent = MESSAGES.videosN.replace( '%n', state.videos.length ).replace( '%c', state.chosen.size ? MESSAGES.videosChosen.replace( '%n', state.chosen.size ) : '' );
				} );
				const th = el( 'img' );
				th.alt = '';
				th.referrerPolicy = 'no-referrer';
				th.src = `https://i.ytimg.com/vi/${ id }/default.jpg`;
				label.append( box, th, document.createTextNode( id ) );
				vids.append( label );
			} );
			fold.append( vids );
			scroll.append( fold );
		} else {
			scroll.append( el( 'div', 'muted', MESSAGES.noVideos ) );
		}
		if ( state.audio.length ) {
			const fold = el( 'details' );
			fold.open = state.audioOpen;
			fold.addEventListener( 'toggle', () => {
				state.audioOpen = fold.open;
			} );
			const sum = () => MESSAGES.audioN.replace( '%n', state.audio.length ).replace( '%c', state.heard.size ? MESSAGES.videosChosen.replace( '%n', state.heard.size ) : '' );
			fold.append( el( 'summary', '', sum() ) );
			const list = el( 'div', 'vids' );
			state.audio.forEach( ( a ) => {
				const label = el( 'label', 'vid' );
				label.title = a.url;
				const box = el( 'input' );
				box.type = 'checkbox';
				box.checked = state.heard.has( a.url );
				box.addEventListener( 'change', () => {
					if ( box.checked ) {
						state.heard.add( a.url );
					} else {
						state.heard.delete( a.url );
					}
					fold.querySelector( 'summary' ).textContent = sum();
				} );
				label.append( box, document.createTextNode( a.name ) );
				list.append( label );
			} );
			fold.append( list );
			scroll.append( fold );
		} else if ( pickFor.part === 'audio' ) {
			scroll.append( el( 'div', 'muted', MESSAGES.noAudio ) );
		}
		if ( state.picks.length && state.kinds.size ) {
			const n = ( k ) => state.picks.filter( ( p ) => kindOf( p.src ) === k ).length;
			scroll.append( el( 'div', 'muted', MESSAGES.sending2.replace( '%new%', n( 'new' ) + n( 'sharper' ) ).replace( '%maybe%', n( 'maybe' ) ? MESSAGES.sendMaybe.replace( '%n', n( 'maybe' ) ) : '' ).replace( '%have%', n( 'have' ) ? MESSAGES.sendHave.replace( '%n', n( 'have' ) ) : '' ) ) );
		}
		const acts = el( 'div', 'acts' );
		const send = el( 'button', 'b pr', state.sending ? MESSAGES.sending : MESSAGES.send );
		send.type = 'button';
		send.disabled = state.sending;
		send.addEventListener( 'click', submit );
		const cancel = el( 'button', 'b', MESSAGES.cancel );
		cancel.type = 'button';
		cancel.addEventListener( 'click', () => close( true ) );
		acts.append( send, cancel );
		bar.replaceChildren( top, scroll, acts );
	}

	/**
	 * A gallery's buttons: Pick the N new (never the ones the product has or that are vetoed; "Pick all N" before the
	 * check has answered), Also the N maybe, Unpick all N. $btn makes one: ( text, run, off ).
	 */
	function galleryButtons( gal, btn ) {
		const by = sortOut( gal.items );
		const checkedAny = gal.items.some( ( it ) => state.kinds.has( it.src ) );
		const fresh = by.new.filter( ( it ) => ! picked( it.key ) );
		const maybe = by.maybe.filter( ( it ) => ! picked( it.key ) );
		const mine = gal.items.filter( ( it ) => picked( it.key ) );
		const out = [];
		const done = () => {
			render();
			renderChip();
		};
		if ( fresh.length ) {
			out.push( btn( ( checkedAny ? MESSAGES.pickNew : MESSAGES.pickAll ).replace( '%n', fresh.length ), () => {
				pickAll( fresh );
				done();
			} ) );
		}
		if ( maybe.length ) {
			out.push( btn( MESSAGES.pickMaybe.replace( '%n', maybe.length ), () => {
				pickAll( maybe );
				done();
			} ) );
		}
		if ( mine.length ) {
			out.push( btn( MESSAGES.unpickAll.replace( '%n', mine.length ), () => {
				unpickAll( mine );
				done();
			} ) );
		}
		return out;
	}

	/** The chip on the gallery under the pointer: what it holds and its buttons. */
	function renderChip() {
		const g = state.hoverGal;
		if ( ! g ) {
			chip.hidden = true;
			return;
		}
		const btn = ( text, run ) => {
			const b = el( 'button', '', text );
			b.type = 'button';
			b.addEventListener( 'click', run );
			return b;
		};
		const buttons = galleryButtons( g, btn );
		if ( buttons[ 0 ] ) {
			buttons[ 0 ].className = 'pr';
		}
		chip.replaceChildren( el( 'span', '', MESSAGES.chip.replace( '%n', g.items.length ) ), ...buttons );
		chip.hidden = false;
	}

	/** The chip sits in the gallery's top right corner, inside the window, clear of the bar. */
	function placeChip() {
		const g = state.hoverGal;
		if ( ! g || chip.hidden || ! g.el.isConnected ) {
			return;
		}
		const r = g.el.getBoundingClientRect();
		const w = chip.offsetWidth;
		const top = Math.max( 4, Math.min( r.top + 4, bar.getBoundingClientRect().top - chip.offsetHeight - 8 ) );
		chip.style.setProperty( '--x', `${ Math.max( 4, Math.min( r.right - w - 4, window.innerWidth - w - 8 ) ) }px` );
		chip.style.setProperty( '--y', `${ top }px` );
	}

	/** A maybe that is the product's own picture: vetoed for the product, so the runner never compares it. */
	async function sameAs( p ) {
		const res = await chrome.runtime.sendMessage( { type: 'veto', url: location.href, product: pickFor.product || 0, picture: p.src } ).catch( () => null );
		if ( res && res.ok ) {
			state.kinds.set( p.src, 'vetoed' );
			state.picks = state.picks.filter( ( x ) => x !== p );
			state.tags = state.tags.map( ( t ) => ( t[ 2 ] === p.key ? [ t[ 0 ], 'vetoed', t[ 2 ] ] : t ) );
		} else {
			state.note = ( res && res.data && res.data.message ) || MESSAGES.reloaded;
		}
		render();
	}

	/** The gallery as the page's layout's Pictures part: the live mapper's Save, for the layout's pages only. */
	async function forLayout( gal ) {
		const l = state.layout;
		const sel = window.mmptMapSelect.combine( document, [ gal.el ] );
		if ( ! sel || sel === gal.el.nodeName.toLowerCase() ) {
			state.note = MESSAGES.noSelector;
			render();
			return;
		}
		const html = window.__mmiExtPageHtml();
		if ( window.__mmiExtIsWall( html ) ) {
			state.note = MESSAGES.wall;
			render();
			return;
		}
		state.saving = true;
		state.note = MESSAGES.layoutSaving.replace( '%l', l.letter );
		render();
		const res = await chrome.runtime.sendMessage( { type: 'map', op: 'save', url: location.href, field: 'pictures', sel, mode: '', html, where: `Gallery picked on the live page ${ location.pathname }`, layout: l.i } ).catch( () => null );
		state.saving = false;
		const d = ( res && res.data ) || {};
		const f = d.field || d; // a Save answers with the layout's part; the browser check's copy only tests
		const say = ( m ) => m.replace( /%l/g, l.letter ).replace( '%f', f.found ?? '?' ).replace( '%o', f.of ?? '?' );
		state.note = res && res.ok ? say( d.field ? MESSAGES.layoutSaved : MESSAGES.layoutTested ) : d.message || MESSAGES.reloaded;
		render();
		if ( res && res.ok && d.field && window.__mmiExtPanel ) {
			window.__mmiExtPanel.refresh();
		}
	}

	/* ── Picking ───────────────────────────────────────────────────────── */

	const fromBar = ( e ) => e.composedPath().includes( host );

	function onPointer( e ) {
		if ( fromBar( e ) || state.sending ) {
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		if ( e.type !== 'click' || ! e.isTrusted ) {
			return; // a page's own replayed clicks (tap libraries) are blocked, never taken as picks
		}
		const img = pictureAt( e.clientX, e.clientY );
		if ( ! img ) {
			return;
		}
		const film = videoThumb( img );
		if ( film && ( film.id || state.videos.length ) ) { // it goes to the video, not the pictures: with none on the page it stays a person's pick
			if ( film.id && ! state.videos.includes( film.id ) ) {
				state.videos.push( film.id );
			}
			if ( film.id ) {
				state.chosen.add( film.id );
			}
			state.videosOpen = true;
			state.note = film.id ? MESSAGES.isVideo : MESSAGES.whichVideo;
			render();
			return;
		}
		state.galleries = galleriesOf( img );
		state.gi = 0;
		const src = bestSrc( img );
		const key = keyOf( src );
		const i = state.picks.findIndex( ( p ) => p.key === key );
		if ( e.shiftKey && state.galleries.length ) {
			pickAll( sortOut( state.galleries[ 0 ].items ).new ); // Shift-click: the gallery's new pictures
		} else if ( i >= 0 ) {
			state.picks.splice( i, 1 );
		} else if ( state.picks.length >= LIMITS.picks ) {
			state.note = MESSAGES.limit.replace( '%n', LIMITS.picks );
		} else if ( src ) {
			const p = { img, src, key, w: 0 };
			state.picks.push( p ); // picked whatever it is: the person is in charge of picks; the notes say what it will do
			measure( p );
			const k = kindOf( src );
			state.note = inOverlay( img ) ? MESSAGES.overlay : ( k === 'have' ? MESSAGES.hasIt : ( k === 'vetoed' ? MESSAGES.isVetoed : '' ) );
		}
		render();
		renderChip();
	}

	function onMove( e ) {
		if ( fromBar( e ) ) {
			state.hover = null; // the chip and the bar: the gallery stays as it was, so its chip can be clicked
			return;
		}
		state.hover = pictureAt( e.clientX, e.clientY );
		if ( state.hover ) {
			const g = galleriesOf( state.hover )[ 0 ] || null;
			if ( g !== state.hoverGal ) {
				state.hoverGal = g;
				renderChip();
			}
		}
	}

	function onKey( e ) {
		if ( e.key === 'Escape' && ! fromBar( e ) ) {
			e.preventDefault();
			close( true );
		}
	}

	const LISTEN = [ [ 'pointerdown', onPointer ], [ 'mousedown', onPointer ], [ 'pointerup', onPointer ], [ 'mouseup', onPointer ], [ 'click', onPointer ], [ 'mousemove', onMove ], [ 'keydown', onKey ] ];

	function close( back ) {
		LISTEN.forEach( ( [ type, fn ] ) => window.removeEventListener( type, fn, true ) );
		host.remove();
		delete window.__mmiExtPicker;
		if ( back && window.__mmiExtPanel ) {
			window.__mmiExtPanel.unfold();
		}
	}

	async function submit() {
		if ( ! state.picks.length && ! state.chosen.size && ! state.heard.size ) {
			state.note = MESSAGES.none;
			render();
			return;
		}
		const html = window.__mmiExtPageHtml();
		if ( window.__mmiExtIsWall( html ) ) {
			state.note = MESSAGES.wall;
			render();
			return;
		}
		state.sending = true;
		render();
		const res = await chrome.runtime.sendMessage( {
			type: 'pictures',
			url: location.href,
			product: pickFor.product || 0,
			pictures: state.picks.map( ( p ) => p.src ),
			videos: Array.from( state.chosen ).map( ( id ) => `https://www.youtube.com/watch?v=${ id }` ),
			audio: Array.from( state.heard ),
			html,
		} ).catch( () => ( { ok: false, data: { message: MESSAGES.reloaded } } ) );
		state.sending = false;
		if ( res && res.ok ) {
			close( false );
			if ( window.__mmiExtPanel ) {
				window.__mmiExtPanel.answer( res );
			}
			return;
		}
		state.note = ( res && res.data && res.data.message ) || MESSAGES.reloaded;
		render();
	}

	window.__mmiExtPicker = {
		show() {
			if ( ! host.isConnected ) {
				document.documentElement.appendChild( host );
			}
		},
	};

	/** The page's pictures against the product's (the picture merge's rules, on the server): labelled on the page. */
	async function check() {
		const found = new Map(); // img → src
		const films = [];
		Array.from( document.images ).filter( offered ).forEach( ( img ) => {
			const src = bestSrc( img );
			if ( src && videoThumb( img ) ) {
				films.push( [ img, 'video', keyOf( src ) ] );
			} else if ( src && otherProduct( img ) ) {
				films.push( [ img, 'other', keyOf( src ) ] );
			} else if ( src ) {
				found.set( img, src );
			}
		} );
		const byKey = new Map();
		found.forEach( ( src ) => byKey.has( keyOf( src ) ) || byKey.set( keyOf( src ), src ) );
		const srcs = [ ...byKey.values() ].slice( 0, LIMITS.check );
		if ( ! srcs.length || ! pickFor.product ) {
			return;
		}
		state.summary = MESSAGES.checking;
		render();
		const res = await chrome.runtime.sendMessage( { type: 'pictures', op: 'check', url: location.href, product: pickFor.product, pictures: srcs } ).catch( () => null );
		const kinds = ( res && res.ok && res.data.kinds ) || {};
		state.layout = ( res && res.ok && res.data.layout ) || null;
		Object.entries( kinds ).forEach( ( [ src, kind ] ) => state.kinds.set( src, kind ) );
		// Every copy of a checked picture shares its answer (a slide and its thumbnail).
		found.forEach( ( src ) => state.kinds.has( src ) || ! byKey.has( keyOf( src ) ) || ! state.kinds.has( byKey.get( keyOf( src ) ) ) || state.kinds.set( src, state.kinds.get( byKey.get( keyOf( src ) ) ) ) );
		state.tags = [ ...films, ...[ ...found ].filter( ( [ , src ] ) => state.kinds.has( src ) ).map( ( [ img, src ] ) => [ img, kindOf( src ), keyOf( src ) ] ) ];
		const n = ( k ) => Object.values( kinds ).filter( ( v ) => k.includes( v ) ).length;
		renderChip();
		state.summary = res && res.ok ? MESSAGES.checked.replace( '%have%', n( [ 'have', 'duplicate' ] ) ).replace( '%maybe%', n( [ 'maybe' ] ) ).replace( '%new%', n( [ 'new', 'sharper' ] ) ).replace( '%vetoed%', n( [ 'vetoed' ] ) ? MESSAGES.vetoedN.replace( '%n%', n( [ 'vetoed' ] ) ) : '' ) : '';
		render();
	}

	state.videos = videosOnPage();
	state.audio = audioOnPage();
	state.videosOpen = pickFor.part === 'videos';
	state.audioOpen = pickFor.part === 'audio';
	LISTEN.forEach( ( [ type, fn ] ) => window.addEventListener( type, fn, true ) );
	render();
	window.requestAnimationFrame( frame );
	check();
}() );
