/**
 * Vendor maps: choosing selectors from picked elements and reading what they give each part, as the
 * server reads it (VendorMaps PHP), for the live mapper that runs on the vendor's own page (live-mapper.js) in the
 * Chrome extension, which carries this file (ExtBridge::BUNDLED). (The stored-page picker that shared it went with
 * the site drawer's Map mode, 2026-11-03.)
 *
 *   const { signature, selectorFor, combine, givesOf, describe, givesLabel, … } = window.mmptMapSelect;
 */
( function () {
	'use strict';

	const LIMITS = { previewChars: 240, tagChars: 300 };
	const TAB_NAME_MAX = 40; // VendorMaps::TAB_NAME_MAX
	// Same rules as VendorMaps::signature() (PHP): classes that are state or generated never anchor a selector.
	const CHROME_CLASS = /^(js-|is-|has-|active|current|lazy|loaded|entered|lazyloaded|swiper-slide-(active|next|prev|visible)|slick-(active|current|cloned)|wp-image-|attachment-|size-|aos|animate|fade|show|hidden|visible|selected|first|last|odd|even)|\d{3,}/i;
	const SAFE_CLASS = /^[A-Za-z_][A-Za-z0-9_-]*$/;
	const SAFE_ID = /^[A-Za-z][A-Za-z0-9_-]{1,40}$/;
	const SKIP_TAGS = [ 'BODY', 'HTML' ];
	const MANUAL_TEXT = /manual|user.?guide|operation|documentation|handbook|reference guide|quick.?start|getting started/i; // VendorMaps::MANUAL_TEXT
	// What each part needs from the picks, as the server reads it (VendorMaps: MIN_TEXT, features 3+, REQ_TEXT, VERSION_RE, VIDEO_RE / VIDEO_ATTR).
	const NEEDS = { text: 200, items: 3, reqText: 40, names: 2 };
	/** Parts read as a list of things: more elements like a pick are what a person means, not a surprise (red dashes are for the rest). */
	const LIST_PARTS = [ 'features', 'pictures', 'videos', 'manual', 'audio', 'contents' ];
	const CONTENTS = { max: 150, name: 120 }; // VendorMaps::CONTENTS_MAX, CONTENTS_NAME
	const REQ_TEXT = /system\s*requirement|requirements|compatib|specification/i;
	const VERSION_RE = /\b(?:version|ver\.?|v)\s*:?\s*(\d{1,3}(?:\.\d{1,4}){1,3})/i;
	const VIDEO_SRC = /youtube(?:-nocookie)?\.com\/(?:embed|watch)|youtu\.be\/|player\.vimeo\.com\/video|vimeo\.com\/\d/i;
	const VIDEO_ATTR = /youtube|video.?id|videoid|yt.?id|embed.?id/i;
	const AUDIO_SRC = /soundcloud\.com\/(?!$)|\.(?:mp3|m4a|wav|ogg|aac|flac)(?:[?#]|$)/i;
	const MESSAGES = {
		gives: {
			pictures: [ '%n% picture', '%n% pictures', 'nothing' ],
			description: [ '%n% character', '%n% characters', 'nothing' ],
			features: [ '%n% feature', '%n% features', 'nothing' ],
			requirements: [ '%n% character', '%n% characters', 'nothing' ],
			version: [ 'version %v%', 'version %v%', 'nothing' ],
			manual: [ '%n% manual link', '%n% manual links', 'nothing' ],
			videos: [ '%n% video', '%n% videos', 'nothing' ],
			audio: [ '%n% audio demo', '%n% audio demos', 'nothing' ],
			disclaimer: [ '%n% character', '%n% characters', 'nothing' ],
			contents: [ '%n% product named', '%n% products named', 'nothing' ],
		},
		reqLink: 'a requirements link',
		// Why an element gives Features nothing (featureVerdicts(): the reader's own rules, owner 2026-10-30)
		why: {
			nolist: 'nothing: Features reads list items or feature cards (3 or more), and this has none',
			requirements: 'nothing: this list reads as system requirements, which Features skips',
			few: 'nothing: fewer than 3 items',
			later: 'nothing: only the first list is read',
			listfirst: 'nothing: a bulleted list on this page is read first',
			smaller: 'nothing: a bigger group of cards is read',
		},
		needs: '%what% (needs %need%)',
		need: { description: '200', features: '3', requirements: 'a link or 40', contents: '2' },
	};

	const fill = ( tpl, vars ) => tpl.replace( /%(\w+)%/g, ( m, k ) => ( k in vars ? String( vars[ k ] ) : m ) );

	/* ── Features as the reader reads them (VendorMaps::read_part(), VendorPage::looks_like_requirements()) ── */

	/** A list showing 3 of these signs reads as system requirements: Features skips it (tabs often style both alike). */
	const REQ_SIGNS = [ /\bmac ?os\b|\bos ?x\b|\bmac\b/i, /\bwindows\b/i, /\b\d+ ?GB\b/i, /\b(AAX|VST ?3?|AU)\b/, /\b(intel|amd|apple silicon|m1|processor|cpu)\b/i, /\b(32|64)[- ]?bit\b/i ];
	const READ_LIMIT = 20; // matched elements the reader looks at
	const looksLikeRequirements = ( t ) => REQ_SIGNS.filter( ( re ) => re.test( t ) ).length >= 3;

	/** Elements a card's words never come from, and the ones that start a new line of them (VendorMaps::CARD_SKIP / CARD_BLOCKS). */
	const CARD_SKIP = [ 'SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'SVG', 'BUTTON', 'SELECT', 'BR' ];
	const CARD_BLOCKS = [ 'DIV', 'P', 'H1', 'H2', 'H3', 'H4', 'H5', 'H6', 'SECTION', 'ARTICLE', 'HEADER', 'FOOTER', 'ASIDE', 'FIGURE', 'FIGCAPTION', 'DT', 'DD', 'BLOCKQUOTE', 'TABLE', 'TR', 'TD', 'TH' ];
	const tagOf = ( el ) => el.nodeName.toUpperCase();

	/** A box's repeated children, the cards of a feature grid: below one-child wrappers, the biggest set of 3+ sharing tag and first class (VendorMaps::card_children()). */
	function cardChildren( el ) {
		let kids = [];
		for ( let depth = 0; depth < 5; depth++ ) {
			kids = Array.from( el.children ).filter( ( c ) => ! CARD_SKIP.includes( tagOf( c ) ) );
			if ( kids.length !== 1 ) {
				break;
			}
			el = kids[ 0 ];
		}
		const by = new Map();
		kids.forEach( ( c ) => {
			const k = `${ tagOf( c ) }.${ ( c.getAttribute( 'class' ) || '' ).trim().split( /\s+/ )[ 0 ] }`;
			by.set( k, ( by.get( k ) || [] ).concat( c ) );
		} );
		let best = [];
		by.forEach( ( g ) => {
			if ( g.length > best.length ) {
				best = g;
			}
		} );
		return best.length >= 3 ? best : [];
	}

	/** A card's words as one feature: its first block is the title, the rest follows a colon (VendorMaps::card_text()). */
	function cardText( el ) {
		const parts = new Map();
		const walk = ( n, at ) => n.childNodes.forEach( ( c ) => {
			if ( c.nodeType === 3 ) {
				parts.set( at, ( parts.get( at ) || '' ) + c.data );
			} else if ( c.nodeType === 1 && ! CARD_SKIP.includes( tagOf( c ) ) ) {
				walk( c, CARD_BLOCKS.includes( tagOf( c ) ) ? c : at );
			}
		} );
		walk( el, el );
		const lines = [ ...parts.values() ].map( ( t ) => t.replace( /\s+/g, ' ' ).trim() ).filter( Boolean );
		const title = lines.shift() || '';
		return lines.length ? `${ title }${ /[.:!?]$/.test( title ) ? ' ' : ': ' }${ lines.join( ' ' ) }` : title;
	}

	/**
	 * The reader's candidates: each matched element, list items matched one by one together with their list's, and
	 * cards (elements holding no list: those of one parent together, or a matched box's repeated children). A card
	 * group carries `cards`, the elements read one item each.
	 */
	function featureLists( els ) {
		const lists = [];
		const byParent = new Map();
		const cardsOf = new Map();
		els.slice( 0, READ_LIMIT ).forEach( ( el ) => {
			if ( el.nodeName === 'LI' ) {
				if ( ! byParent.has( el.parentNode ) ) {
					byParent.set( el.parentNode, lists.length );
					lists.push( [] );
				}
				lists[ byParent.get( el.parentNode ) ].push( el );
				return;
			}
			if ( ! [ 'UL', 'OL' ].includes( el.nodeName ) && ! el.querySelector( 'li' ) ) {
				const kids = cardChildren( el );
				if ( kids.length ) {
					lists.push( Object.assign( [ el ], { cards: kids } ) );
					return;
				}
				if ( ! cardsOf.has( el.parentNode ) ) {
					const group = [];
					group.cards = group;
					cardsOf.set( el.parentNode, group );
					lists.push( group );
				}
				cardsOf.get( el.parentNode ).push( el );
				return;
			}
			lists.push( [ el ] );
		} );
		return lists;
	}

	/** A candidate's items: 3 to 400 characters, each once (the reader also leaves out shop lines, which this doesn't). */
	function featureItems( group ) {
		const texts = group.cards
			? group.cards.map( cardText )
			: group.flatMap( ( el ) => ( el.nodeName === 'LI' ? [ el ] : Array.from( [ 'UL', 'OL' ].includes( el.nodeName ) ? el.children : el.querySelectorAll( 'li' ) ).filter( ( li ) => li.nodeName === 'LI' ) ) ).map( ( li ) => li.textContent.replace( /\s+/g, ' ' ).trim() );
		return [ ...new Set( texts.filter( ( t ) => t.length >= 3 && t.length <= 400 ) ) ];
	}

	/**
	 * What the reader does with each element a Features selector matches: it reads the first list with 3+ items,
	 * skipping lists that read as system requirements; only where no list gives 3, the biggest group of cards.
	 * { read: items[], why: Map( el → '' read | 'nolist' | 'requirements' | 'few' | 'later' | 'listfirst' | 'smaller' ) }.
	 */
	function featureVerdicts( els ) {
		const why = new Map();
		const groups = featureLists( els );
		let best = null;
		let items = [];
		let stopped = false;
		groups.filter( ( g ) => ! g.cards ).forEach( ( group ) => {
			if ( stopped ) {
				group.forEach( ( el ) => why.set( el, 'later' ) );
				return;
			}
			const its = featureItems( group );
			const verdict = ! its.length ? 'nolist' : ( looksLikeRequirements( its.join( ' ' ) ) ? 'requirements' : 'few' );
			group.forEach( ( el ) => why.set( el, verdict ) );
			if ( verdict === 'few' && its.length > items.length ) {
				best = group;
				items = its;
			}
			stopped = items.length >= NEEDS.items;
		} );
		els.slice( READ_LIMIT ).forEach( ( el ) => why.set( el, 'later' ) );
		if ( best && items.length >= NEEDS.items ) {
			best.forEach( ( el ) => why.set( el, '' ) );
			// lists after the one read were never reached; those before it held fewer items
			let after = false;
			why.forEach( ( v, el ) => {
				if ( best.includes( el ) ) {
					after = true;
				} else if ( after && v === 'few' ) {
					why.set( el, 'later' );
				}
			} );
		}
		let read = items.length >= NEEDS.items ? items : [];
		const cards = groups.filter( ( g ) => g.cards );
		let most = null;
		let mostItems = [];
		cards.forEach( ( group ) => {
			const its = featureItems( group );
			const verdict = ! its.length ? 'nolist' : ( looksLikeRequirements( its.join( ' ' ) ) ? 'requirements' : 'few' );
			group.forEach( ( el ) => why.set( el, read.length ? 'listfirst' : verdict ) );
			if ( ! read.length && verdict === 'few' && its.length > mostItems.length ) {
				most = group;
				mostItems = its;
			}
		} );
		if ( ! read.length && most && mostItems.length >= NEEDS.items ) {
			cards.forEach( ( group ) => group.forEach( ( el ) => why.get( el ) === 'few' && why.set( el, 'smaller' ) ) );
			most.forEach( ( el ) => why.set( el, '' ) );
			read = mostItems;
		}
		return { read, why };
	}

	/* ── Selectors (the server's CSS subset) ───────────────────────────── */

	/**
	 * The classes and ids of the page as the reader has it, usually as delivered, before its scripts run (the live mapper
	 * sets them: setKnown(); a site stored as a browser built it gives our stored copy's).
	 * A selector built from a slider's own classes ("slick-slide", ids "slick-slide00") matches nothing on the stored
	 * pages the server reads (owner 2026-11-03, soundiron). null: every class counts (the stored view is delivered HTML).
	 */
	let known = null;
	const setKnown = ( doc ) => {
		known = { classes: new Set(), ids: new Set() };
		doc.querySelectorAll( '[class], [id]' ).forEach( ( e ) => {
			( e.getAttribute( 'class' ) || '' ).split( /\s+/ ).forEach( ( c ) => c && known.classes.add( c ) );
			if ( e.id ) {
				known.ids.add( e.id );
			}
		} );
	};

	function signature( el ) {
		const parts = [];
		let node = el;
		let depth = 0;
		while ( node && node.nodeType === 1 && parts.length < 3 && depth < 8 && ! SKIP_TAGS.includes( node.nodeName ) ) {
			depth++;
			const tag = node.nodeName.toLowerCase();
			const id = node.getAttribute( 'id' ) || '';
			const cls = ( node.getAttribute( 'class' ) || '' ).trim().split( /\s+/ ).filter( ( c ) => c && ! CHROME_CLASS.test( c ) && SAFE_CLASS.test( c ) && ( ! known || known.classes.has( c ) ) );
			if ( id && SAFE_ID.test( id ) && ! /\d{3,}/.test( id ) && ( ! known || known.ids.has( id ) ) ) {
				parts.unshift( `${ tag }#${ id }` );
				break;
			}
			if ( cls.length || node === el ) {
				parts.unshift( tag + ( cls.length ? `.${ cls.slice( 0, 2 ).join( '.' ) }` : '' ) );
			}
			node = node.parentElement;
		}
		return parts.length >= 2 || ( parts.length && /[#.]/.test( parts[ 0 ] ) ) ? parts.join( ' ' ) : '';
	}

	/** The element's own signature, or the nearest ancestor's plus its tag. */
	function selectorFor( el ) {
		const own = signature( el );
		if ( own ) {
			return own;
		}
		for ( let node = el.parentElement; node && ! SKIP_TAGS.includes( node.nodeName ); node = node.parentElement ) {
			const s = signature( node );
			if ( s ) {
				return `${ s } ${ el.nodeName.toLowerCase() }`;
			}
		}
		return el.nodeName.toLowerCase();
	}

	/** [2, 0, 1] before [2, 1, 0]: compare scores field by field. */
	function lexLess( a, b ) {
		for ( let i = 0; i < a.length; i++ ) {
			if ( a[ i ] !== b[ i ] ) {
				return a[ i ] < b[ i ];
			}
		}
		return false;
	}

	/** Elements a selector matches (null when the browser doesn't understand it). `step:tab("name")` as the server reads it. */
	function matchAll( doc, sel ) {
		const marked = [];
		try {
			let i = 0;
			const css = String( sel ).replace( TAB_STEP, ( m, step, name ) => {
				const attr = `data-mmpt-tab${ i++ }`;
				doc.querySelectorAll( step || '*' ).forEach( ( el ) => {
					if ( tabNamed( el, step || '*', name ) ) {
						el.setAttribute( attr, '' ); // the browser has no :tab(); the panels are marked for this one query
						marked.push( [ el, attr ] );
					}
				} );
				return `${ step }[${ attr }]`;
			} );
			return Array.from( doc.querySelectorAll( css ) );
		} catch ( e ) {
			return null;
		} finally {
			marked.forEach( ( [ el, attr ] ) => el.removeAttribute( attr ) );
		}
	}

	/* ── Tabs a script switches: names listed first, then one panel per name (VendorMaps::tab_step()) ── */

	const TAB_STEP = /([^\s>,]*):tab\("([^"]*)"\)/g;
	const tabText = ( el ) => el.textContent.replace( /[ \t\n\r]+/g, ' ' ).trim().replace( /[A-Z]/g, ( c ) => c.toLowerCase() ); // as XPath's normalize-space() and translate() see it
	const tabSame = ( t, name ) => !! t && ( t.includes( name ) || name.includes( t ) );

	/** The list naming el's tabs: the nearest ul/ol before it, with one item per panel of el's kind beside it. */
	function tabRow( el, kind ) {
		let list = el.previousElementSibling;
		while ( list && ! [ 'UL', 'OL' ].includes( list.nodeName ) ) {
			list = list.previousElementSibling;
		}
		if ( ! list || ! el.parentElement ) {
			return null;
		}
		const items = Array.from( list.children ).filter( ( c ) => c.nodeName === 'LI' );
		const panels = Array.from( el.parentElement.children ).filter( ( c ) => c.matches( kind ) );
		return items.length === panels.length ? { items, panels } : null;
	}

	function tabNamed( el, kind, name ) {
		const row = tabRow( el, kind );
		const at = row ? row.items.findIndex( ( li ) => tabSame( tabText( li ), name ) ) : -1;
		return at >= 0 && row.panels.indexOf( el ) === at;
	}

	/** The tab el sits in: { panel, step, name }, or null. Only a panel with a step of its own, among 2 or more, named by a short item. */
	function tabOf( el ) {
		for ( let node = el; node && node.parentElement && ! SKIP_TAGS.includes( node.nodeName ); node = node.parentElement ) {
			const step = [ 'UL', 'OL', 'LI' ].includes( node.nodeName ) ? '' : stepOf( node );
			const row = step ? tabRow( node, step ) : null;
			if ( row && row.panels.length >= 2 ) {
				const name = tabText( row.items[ row.panels.indexOf( node ) ] ).replace( /"/g, '' );
				if ( name && name.length <= TAB_NAME_MAX ) {
					return { panel: node, step, name };
				}
			}
		}
		return null;
	}

	/**
	 * Picks all in one tab keep to it: each selector of the list that takes them gets `step:tab("name")` (owner 2026-11-01:
	 * Requirements picked on acondigital.com's Acoustica took the Features and New in Version 2 tabs on the 13 other pages,
	 * whose tabs come in another order). Picks in different tabs, or in none, leave the selector as it is.
	 */
	function withTabs( doc, sel, picks ) {
		if ( ! sel || sel.includes( ':tab(' ) ) {
			return sel;
		}
		return sel.split( /\s*,\s*/ ).map( ( one ) => {
			const m = matchAll( doc, one ) || [];
			const mine = picks.filter( ( p ) => m.some( ( x ) => x === p || x.contains( p ) || p.contains( x ) ) );
			const tabs = mine.map( tabOf );
			const t = tabs[ 0 ];
			if ( ! t || tabs.some( ( x ) => ! x || x.step !== t.step || x.name !== t.name ) ) {
				return one;
			}
			const steps = one.split( ' ' );
			const at = steps.indexOf( t.step );
			const scoped = at >= 0 ? steps.map( ( s, i ) => ( i === at ? `${ s }:tab("${ t.name }")` : s ) ).join( ' ' ) : `${ t.step }:tab("${ t.name }") ${ one }`;
			const still = matchAll( doc, scoped ) || [];
			return mine.every( ( p ) => still.some( ( x ) => x === p || x.contains( p ) || p.contains( x ) ) ) ? scoped : one; // never drops a pick
		} ).join( ', ' );
	}

	/**
	 * Matches of a selector that are none of the picks, not inside one and not around one: what it would read that nobody
	 * picked. With part $k given, a manual's extras are only those with a manual link: the reader takes links, so a spec
	 * line with none adds nothing (owner 2026-11-03: soundiron's 8 spec lines flagged beside its manual's line).
	 */
	function extrasOf( doc, sel, picks, k ) {
		const m = matchAll( doc, sel ) || [];
		return m.filter( ( e ) => ! picks.some( ( p ) => p === e || p.contains( e ) || e.contains( p ) ) && ( k === 'manual' ? takenFrom( 'manual', [ e ] ).length > 0 : givesAnything( e ) ) );
	}

	/** An element a part could read something from: text, or a picture or player. An empty box adds nothing. */
	const givesAnything = ( e ) => e.nodeName === 'IMG' || e.textContent.trim() !== '' || !! e.querySelector( 'img, iframe, video, audio, source' );

	/**
	 * ":not(.related)" for the unpicked elements a selector also takes: a class or id each of them carries and no pick
	 * does (owner 2026-11-02: soundiron's description box and its "Related products" strip are both div.wrapper).
	 */
	function exclusion( extras, picks ) {
		const marks = ( e ) => {
			const id = e.getAttribute( 'id' ) || '';
			const cls = ( e.getAttribute( 'class' ) || '' ).trim().split( /\s+/ ).filter( ( c ) => c && ! CHROME_CLASS.test( c ) && SAFE_CLASS.test( c ) );
			return [ ...( id && SAFE_ID.test( id ) && ! /\d{3,}/.test( id ) ? [ `#${ id }` ] : [] ), ...cls.map( ( c ) => `.${ c }` ) ];
		};
		const taken = new Set( picks.flatMap( marks ) );
		const out = [];
		for ( const e of extras ) {
			const own = marks( e ).filter( ( m ) => ! taken.has( m ) );
			if ( ! own.length ) {
				return ''; // one of them looks just like a pick: :not() can't leave it out
			}
			if ( ! own.some( ( m ) => out.includes( m ) ) ) {
				out.push( own[ 0 ] );
			}
		}
		return out.length && out.length <= 3 ? out.map( ( m ) => `:not(${ m })` ).join( '' ) : '';
	}

	/** An element as a selector step: tag#id (a stable id) or tag with up to two stable classes; '' when it has neither. */
	function stepOf( el ) {
		const tag = el.nodeName.toLowerCase();
		const id = el.getAttribute( 'id' ) || '';
		if ( id && SAFE_ID.test( id ) && ! /\d{3,}/.test( id ) && ( ! known || known.ids.has( id ) ) ) {
			return `${ tag }#${ id }`;
		}
		const cls = ( el.getAttribute( 'class' ) || '' ).trim().split( /\s+/ ).filter( ( c ) => c && ! CHROME_CLASS.test( c ) && SAFE_CLASS.test( c ) && ( ! known || known.classes.has( c ) ) );
		return cls.length ? `${ tag }.${ cls.slice( 0, 2 ).join( '.' ) }` : '';
	}

	/**
	 * A narrower selector for picks of one kind when the general one also takes elements nobody picked
	 * (owner 2026-10-10: three description paragraphs picked, and the selector also took the sidebar
	 * blurb, a hidden price line and a store promo). Tries the direct-child form and an ancestor that
	 * tells them apart; keeps the one that takes the fewest unpicked elements, then the simplest. Page
	 * structure only: an attribute such as [style] told the promo apart on one page and lost 31 of 73.
	 */
	function narrowest( doc, els, s, all ) {
		const tag = els[ 0 ].nodeName.toLowerCase();
		const own = els.every( ( e ) => stepOf( e ) === stepOf( els[ 0 ] ) ) ? ( stepOf( els[ 0 ] ) || tag ) : tag;
		const forms = [ { sel: s, cost: 0 } ];
		const parent = els[ 0 ].parentElement;
		if ( parent && ! SKIP_TAGS.includes( parent.nodeName ) && els.every( ( e ) => e.parentElement && ( stepOf( e.parentElement ) || e.parentElement.nodeName ) === ( stepOf( parent ) || parent.nodeName ) ) ) {
			const up = signature( parent ) || stepOf( parent );
			if ( up ) {
				forms.push( { sel: `${ up } > ${ own }`, cost: 1 } );
			}
		}
		const ancestors = [];
		for ( let a = parent && parent.parentElement, i = 0; a && ! SKIP_TAGS.includes( a.nodeName ) && i < 12; a = a.parentElement, i++ ) {
			const t = stepOf( a );
			if ( t && els.every( ( e ) => a.contains( e ) ) && ! s.includes( t ) && ! ancestors.includes( t ) ) {
				ancestors.push( t );
			}
		}
		// Each form again without its unpicked look-alikes (":not(.x)" on its last step), when they carry a mark of their own.
		forms.slice().forEach( ( f ) => {
			const m = matchAll( doc, f.sel );
			const not = m && ! /:tab\(/.test( f.sel ) ? exclusion( m.filter( ( x ) => ! all.some( ( p ) => p === x || p.contains( x ) || x.contains( p ) ) && givesAnything( x ) ), els ) : '';
			if ( not ) {
				forms.push( { sel: f.sel + not, cost: f.cost + 1 } );
			}
		} );
		let best = null;
		forms.forEach( ( f ) => [ '', ...ancestors ].forEach( ( t, ai ) => {
			const sel = `${ t ? `${ t } ` : '' }${ f.sel }`;
			const m = matchAll( doc, sel );
			if ( ! m || ! els.every( ( e ) => m.some( ( x ) => x === e || x.contains( e ) ) ) ) {
				return; // must still take every pick
			}
			const score = [ extrasOf( doc, sel, all ).length, ( t ? 1 : 0 ) + f.cost, ai, sel.length ];
			if ( ! best || lexLess( score, best.score ) ) {
				best = { sel, score };
			}
		} ) );
		return best ? best.sel : s;
	}

	/**
	 * Several picks → one selector list ("a, b"); a pick another pick's selector already takes adds
	 * nothing. When that list also takes elements nobody picked, each kind of pick gets the narrowest
	 * form that doesn't (narrowest()).
	 */
	function combine( doc, els ) {
		return withTabs( doc, combineTight( doc, els ), els );
	}

	function combineTight( doc, els ) {
		const base = combineLoose( doc, els );
		if ( ! base || ! extrasOf( doc, base, els ).length ) {
			return base;
		}
		const groups = new Map();
		els.forEach( ( el ) => {
			const s = selectorFor( el );
			groups.set( s, [ ...( groups.get( s ) || [] ), el ] );
		} );
		const sels = [];
		groups.forEach( ( group, s ) => {
			const n = narrowest( doc, group, s, els );
			if ( ! sels.includes( n ) ) {
				sels.push( n );
			}
		} );
		const tight = sels.join( ', ' );
		return extrasOf( doc, tight, els ).length < extrasOf( doc, base, els ).length ? tight : base;
	}

	function combineLoose( doc, els ) {
		const sels = [];
		els.forEach( ( el ) => {
			const s = selectorFor( el );
			if ( sels.includes( s ) ) {
				return;
			}
			const covered = sels.some( ( t ) => {
				try {
					return Array.from( doc.querySelectorAll( t ) ).some( ( m ) => m === el || m.contains( el ) );
				} catch ( e ) {
					return false;
				}
			} );
			if ( ! covered ) {
				sels.push( s );
			}
		} );
		return sels.join( ', ' );
	}

	/**
	 * The feature card an element sits in: itself or the nearest ancestor (6 up) with a stable class that 3 or more
	 * of its parent's children share (tag and first class, as cardChildren() groups them). null when there is none.
	 * { el, n: cards beside it, step } (owner 2026-10-31: a card's text picked alone read as "1 feature (needs 3)").
	 */
	function cardOf( el ) {
		for ( let node = el, i = 0; node && node.parentElement && ! SKIP_TAGS.includes( node.nodeName ) && i < 6; node = node.parentElement, i++ ) {
			const step = stepOf( node );
			if ( ! step || step.includes( '#' ) ) {
				continue;
			}
			const kind = `${ tagOf( node ) }.${ ( node.getAttribute( 'class' ) || '' ).trim().split( /\s+/ )[ 0 ] }`;
			const n = Array.from( node.parentElement.children ).filter( ( c ) => `${ tagOf( c ) }.${ ( c.getAttribute( 'class' ) || '' ).trim().split( /\s+/ )[ 0 ] }` === kind ).length;
			if ( n >= NEEDS.items ) {
				return { el: node, n, step };
			}
		}
		return null;
	}

	/** An element's opening tag, as the hover tip shows it (text, never HTML). */
	function openingTag( el ) {
		const outer = el.outerHTML || '';
		const end = outer.indexOf( '>' );
		const tag = end >= 0 ? outer.slice( 0, end + 1 ) : outer;
		return tag.length > LIMITS.tagChars ? `${ tag.slice( 0, LIMITS.tagChars ) }…` : tag;
	}

	/* ── What elements give a part (as the server reads them) ─────────── */

	/** What the reader takes from these elements: pictures → the images under them, manual → their manual links. */
	function takenFrom( k, els ) {
		if ( k === 'pictures' ) {
			return els.flatMap( ( e ) => ( e.nodeName === 'IMG' ? [ e ] : Array.from( e.querySelectorAll( 'img' ) ) ) );
		}
		if ( k === 'manual' ) {
			return [ ...new Set( els.flatMap( ( e ) => ( e.nodeName === 'A' ? [ e ] : Array.from( e.querySelectorAll( 'a[href]' ) ) ) ) ) ]
				.filter( ( a ) => MANUAL_TEXT.test( `${ a.textContent } ${ a.getAttribute( 'href' ) || '' }` ) );
		}
		return els;
	}

	/**
	 * The products a Bundle contents pick names, as VendorMaps::read_part() reads them: one per list item (top level), table
	 * row, repeated card or picked element; its name is a heading in it, else its link's words, else bold words, else its own
	 * first words. A name met twice counts once. @return string[]
	 */
	function contentNames( els ) {
		const clean = ( t ) => String( t || '' ).replace( /\s+/g, ' ' ).replace( /^[\s•·–—:|✓✔►▸*-]+|[\s•·–—:|✓✔►▸*-]+$/g, '' );
		const items = ( el ) => {
			const tag = tagOf( el );
			if ( [ 'LI', 'TR', 'A', 'H2', 'H3', 'H4', 'H5', 'H6', 'DT' ].includes( tag ) ) {
				return [ el ];
			}
			if ( tag === 'TABLE' || tag === 'TBODY' ) {
				return Array.from( el.querySelectorAll( 'tr' ) ).filter( ( tr ) => tr.querySelector( 'td' ) );
			}
			const top = Array.from( el.querySelectorAll( 'li' ) ).filter( ( li ) => {
				let up = li.parentElement;
				while ( up && up !== el && tagOf( up ) !== 'LI' ) {
					up = up.parentElement;
				}
				return up === el;
			} );
			if ( top.length ) {
				return top;
			}
			const cards = cardChildren( el );
			return cards.length ? cards : [ el ];
		};
		const first = ( it, q ) => {
			for ( const h of it.querySelectorAll( q ) ) {
				const t = clean( h.textContent );
				if ( t ) {
					return t;
				}
			}
			return '';
		};
		const seen = new Map();
		els.forEach( ( el ) => items( el ).forEach( ( it ) => {
			const link = tagOf( it ) === 'A' ? it : it.querySelector( 'a[href]' );
			let name = first( it, 'h1, h2, h3, h4, h5, h6' ) || ( link ? clean( link.textContent ) : '' ) || first( it, 'strong, b' );
			if ( ! name ) {
				name = clean( Array.from( it.childNodes ).filter( ( c ) => ! ( c.nodeType === 1 && [ 'UL', 'OL', 'SCRIPT', 'STYLE', 'BUTTON', 'SELECT' ].includes( tagOf( c ) ) ) ).map( ( c ) => c.textContent ).join( ' ' ) );
				if ( name.length > CONTENTS.name ) {
					name = clean( name.split( /\s[–—-]\s|:\s|\.\s/ )[ 0 ] );
				}
			}
			name = name.replace( /\s*\(?\s*(?:[$€£]\s?\d[\d.,]*|\d[\d.,]*\s?(?:USD|EUR|GBP))\s*\)?\s*$/, '' ).trim();
			if ( name.length >= 2 && name.length <= CONTENTS.name && /\p{L}/u.test( name ) && ! seen.has( name.toLowerCase() ) ) {
				seen.set( name.toLowerCase(), name );
			}
		} ) );
		return [ ...seen.values() ].slice( 0, CONTENTS.max );
	}

	/* ── Links: where a link goes, as a person says it ────────────────── */

	const FILE_KINDS = { pdf: 'PDF', zip: 'ZIP', dmg: 'Mac installer', pkg: 'Mac installer', exe: 'Windows installer', msi: 'Windows installer', doc: 'Word file', docx: 'Word file', txt: 'text file', rtf: 'text file', html: 'page', htm: 'page' };
	const REDIRECT_KEYS = [ 'url', 'u', 'q', 'target', 'dest', 'destination', 'redirect', 'redirect_to', 'to', 'link' ];
	const decode = ( t ) => {
		try {
			return decodeURIComponent( t );
		} catch ( e ) {
			return t;
		}
	};

	/**
	 * Where a link goes, decoded (owner 2026-11-03: "decode the hrefs behind links on hover"): a redirector's ?url= is
	 * followed, %20 read as spaces. { url, host, file, kind ('PDF', 'page', 'this page' …), manual: the reader takes it
	 * as a manual (MANUAL_TEXT on its words and address, as VendorMaps reads it) } or null for no address.
	 */
	function linkInfo( a ) {
		const raw = ( a.getAttribute( 'href' ) || '' ).trim();
		if ( raw === '' || raw.startsWith( 'javascript:' ) ) {
			return null;
		}
		let u;
		try {
			u = new URL( raw, a.ownerDocument.baseURI );
		} catch ( e ) {
			return null;
		}
		for ( const key of REDIRECT_KEYS ) {
			const v = u.searchParams.get( key );
			if ( v && /^https?:\/\//i.test( v ) ) {
				try {
					u = new URL( v );
					break;
				} catch ( e ) {}
			}
		}
		const here = new URL( a.ownerDocument.baseURI );
		const file = decode( u.pathname.split( '/' ).filter( Boolean ).pop() || '' );
		const ext = ( /\.([a-z0-9]{2,5})$/i.exec( file ) || [] )[ 1 ] || '';
		const same = u.origin === here.origin && u.pathname === here.pathname;
		const kind = /^https?:$/.test( u.protocol ) ? ( same ? 'this page' : FILE_KINDS[ ext.toLowerCase() ] || ( ext && ! /^(php|aspx?|jsp)$/i.test( ext ) ? ext.toUpperCase() + ' file' : 'page' ) ) : u.protocol.replace( ':', '' );
		const words = String( a.textContent || '' ).replace( /\s+/g, ' ' ).trim();
		return { url: u.href, host: u.hostname.replace( /^www\./, '' ), file: file || decode( u.pathname ), kind, words, manual: /^https?:$/.test( u.protocol ) && MANUAL_TEXT.test( `${ words } ${ raw }` ) };
	}

	/** "PDF on dist.soundiron.com · Soundiron - Jewel Bandolero - User Manual - v1.1.pdf" */
	function linkSaid( i ) {
		return i.kind === 'this page' ? `a spot on this page (${ i.url.split( '#' )[ 1 ] ? '#' + i.url.split( '#' )[ 1 ] : 'no anchor' })` : `${ i.kind } on ${ i.host }${ i.file ? ` · ${ i.file }` : '' }`;
	}

	/**
	 * What a click means for a part read from links (Manual): the link itself, the link it is inside, or the one manual
	 * link it holds (owner 2026-11-03: a click on soundiron's "Click here for PDF user manual" line took the <li>, and its
	 * selector took the 8 spec lines beside it). Other parts: the element as clicked.
	 */
	function linkFor( k, el ) {
		if ( k !== 'manual' ) {
			return el;
		}
		const up = el.closest( 'a[href]' );
		if ( up ) {
			return up;
		}
		const own = takenFrom( 'manual', [ el ] );
		return own.length === 1 ? own[ 0 ] : el;
	}

	/**
	 * What a picture says: its alt, else its title; '' for none, "-" or a file name. VendorMaps::icon_text() is the
	 * server's side: picked requirements read badges by it (soundiron, 2026-11-02: "Mac OSX 10.9 or later" is only alt text).
	 */
	function iconText( img ) {
		for ( const a of [ 'alt', 'title' ] ) {
			const t = ( img.getAttribute( a ) || '' ).replace( /\s+/g, ' ' ).trim();
			if ( /\p{L}.*\p{L}/u.test( t ) && ! /\.(?:png|jpe?g|gif|svg|webp|avif)$/i.test( t ) ) {
				return t.slice( 0, 300 );
			}
		}
		return '';
	}

	/** An element's text with its pictures' words. */
	const withIcons = ( el ) => `${ el.textContent } ${ ( el.nodeName === 'IMG' ? [ el ] : Array.from( el.querySelectorAll( 'img' ) ) ).map( iconText ).join( ' ' ) }`;

	/**
	 * What some elements give one part, read the way the server reads it (nested ones once):
	 * { n, ok, v?, link? }. ok = enough for that part to count as found.
	 */
	function givesOf( k, els ) {
		const top = els.filter( ( el ) => ! els.some( ( o ) => o !== el && o.contains( el ) ) );
		const text = () => top.map( ( el ) => ( k === 'requirements' ? withIcons( el ) : el.textContent ).replace( /\s+/g, ' ' ).trim() ).join( ' ' ).trim();
		switch ( k ) {
			case 'pictures': { // a slide and its thumbnail are one picture
				const n = new Set( takenFrom( k, top ).map( ( i ) => keyOf( bestSrc( i ) ) ).filter( Boolean ) ).size;
				return { n, ok: n > 0 };
			}
			case 'manual': {
				const n = takenFrom( k, top ).length;
				return { n, ok: n > 0 };
			}
			case 'description': {
				const n = text().length;
				return { n, ok: n >= NEEDS.text };
			}
			case 'disclaimer': {
				const n = text().length;
				return { n, ok: n > 0 };
			}
			case 'features': {
				// what the reader reads: one list, or else one group of cards (owner 2026-10-30: requirement lists looked like Features here)
				const v = featureVerdicts( els );
				const kinds = [ ...new Set( v.why.values() ) ];
				const n = v.read.length || Math.max( 0, ...featureLists( els ).filter( ( g ) => v.why.get( g[ 0 ] ) === 'few' ).map( ( g ) => featureItems( g ).length ) );
				return { n, ok: n >= NEEDS.items, why: n ? '' : ( kinds.includes( 'requirements' ) ? 'requirements' : ( kinds.includes( 'few' ) ? 'few' : 'nolist' ) ) };
			}
			case 'requirements': {
				const link = top.some( ( el ) => {
					const a = el.nodeName === 'A' ? el : el.querySelector( 'a[href]' );
					return !! a && REQ_TEXT.test( `${ el.textContent } ${ a.getAttribute( 'href' ) || '' }` );
				} );
				const n = text().length;
				return { n, link, ok: link || n >= NEEDS.reqText };
			}
			case 'version': {
				const t = text();
				const m = VERSION_RE.exec( t ) || ( t.length < 24 ? /^\D{0,12}?(\d{1,3}(?:\.\d{1,4}){1,3})/.exec( t ) : null );
				return { n: m ? 1 : 0, v: m ? m[ 1 ] : '', ok: !! m };
			}
			case 'videos': {
				const isVideo = ( e ) => ( [ 'IFRAME', 'A', 'SOURCE', 'VIDEO' ].includes( e.nodeName ) && VIDEO_SRC.test( `${ e.getAttribute( 'src' ) || '' } ${ e.getAttribute( 'href' ) || '' }` ) )
					|| Array.from( e.attributes ).some( ( a ) => VIDEO_ATTR.test( a.name ) && a.value !== '' );
				const n = top.flatMap( ( el ) => [ el, ...el.querySelectorAll( 'iframe, a[href], video, source, [data-video-id], [data-videoid], [data-youtube], [videoid]' ) ] ).filter( isVideo ).length;
				return { n, ok: n > 0 };
			}
			case 'contents': { // the products a bundle's page names (VendorMaps::RELATIONS): 2 or more
				const n = contentNames( top ).length;
				return { n, ok: n >= NEEDS.names };
			}
			case 'audio': {
				// SoundCloud players and tracks, <audio> and audio files: what VendorPage::fields() reads as audio demos.
				const isAudio = ( e ) => e.nodeName === 'AUDIO' || AUDIO_SRC.test( `${ e.getAttribute( 'src' ) || '' } ${ e.getAttribute( 'href' ) || '' }` );
				const n = top.flatMap( ( el ) => [ el, ...el.querySelectorAll( 'iframe, a[href], audio, source' ) ] ).filter( isAudio ).length;
				return { n, ok: n > 0 };
			}
			default:
				return { n: 0, ok: false };
		}
	}

	/**
	 * What one element gives a part, to show the person before they save (owner 2026-10-10: "a preview for
	 * each element is needed so the user can be sure what they've selected"):
	 * { tag, text, chars, pictures:[{src,w,h}], items:[], links:[{text,href}], videos:[] }.
	 */
	function describe( k, el ) {
		const clean = ( t ) => String( t || '' ).replace( /\s+/g, ' ' ).trim();
		const text = clean( k === 'requirements' ? withIcons( el ) : el.textContent );
		const d = { tag: openingTag( el ).slice( 0, 120 ), text: text.slice( 0, LIMITS.previewChars ), chars: text.length };
		if ( k === 'pictures' ) {
			d.pictures = takenFrom( 'pictures', [ el ] ).slice( 0, 4 ).map( ( i ) => ( { src: i.currentSrc || i.src || '', w: i.naturalWidth || Number( i.getAttribute( 'width' ) ) || 0, h: i.naturalHeight || Number( i.getAttribute( 'height' ) ) || 0 } ) );
		} else if ( k === 'features' ) {
			d.items = ( el.nodeName === 'LI' ? [ el ] : Array.from( el.querySelectorAll( 'li' ) ) ).slice( 0, 8 ).map( ( li ) => clean( li.textContent ).slice( 0, 120 ) );
		} else if ( k === 'contents' ) {
			d.items = contentNames( [ el ] ).slice( 0, 8 );
		} else if ( k === 'manual' || k === 'requirements' ) {
			const as = k === 'manual' ? takenFrom( 'manual', [ el ] ) : ( el.nodeName === 'A' ? [ el ] : Array.from( el.querySelectorAll( 'a[href]' ) ) ).filter( ( a ) => REQ_TEXT.test( `${ a.textContent } ${ a.getAttribute( 'href' ) || '' }` ) );
			d.links = as.slice( 0, 4 ).map( ( a ) => ( { text: clean( a.textContent ).slice( 0, 80 ), href: a.href, info: linkInfo( a ) } ) );
		} else if ( k === 'videos' ) {
			d.videos = [ el, ...el.querySelectorAll( 'iframe, a[href], source' ) ].map( ( e ) => e.getAttribute( 'src' ) || e.getAttribute( 'href' ) || '' ).filter( ( u ) => VIDEO_SRC.test( u ) ).slice( 0, 4 );
		}
		return d;
	}

	/** "3 pictures", "196 characters (needs 200)", "a requirements link", "nothing" … */
	function givesLabel( k, g ) {
		const [ one, many, none ] = MESSAGES.gives[ k ] || [ '%n%', '%n%', 'nothing' ];
		if ( k === 'requirements' && g.link ) {
			return MESSAGES.reqLink;
		}
		if ( ! g.n ) {
			return g.why && MESSAGES.why[ g.why ] ? MESSAGES.why[ g.why ] : none;
		}
		const what = fill( g.n === 1 ? one : many, { n: g.n.toLocaleString(), v: g.v || '' } );
		return ! g.ok && MESSAGES.need[ k ] ? fill( MESSAGES.needs, { what, need: MESSAGES.need[ k ] } ) : what;
	}

	/** A Magento gallery's box: a script draws inside it, and the reader reads the gallery's data (VendorPage::magento_gallery()). */
	const GALLERY = '[data-gallery-role="gallery-placeholder"]';

	/* ── A picture's full-size address (the picker, the mapper; Enrichment::full_size() on the server) ── */

	const IMG_FILE = /\.(?:jpe?g|png|webp|avif|gif)(?:[?#]|$)/i;
	const ZOOM_ATTRS = [ 'data-zoom-image', 'data-large_image', 'data-large-image', 'data-zoom', 'data-full', 'data-original', 'data-src-large', 'data-hires' ];

	const abs = ( u ) => {
		try {
			return new URL( u, location.href ).href;
		} catch ( e ) {
			return '';
		}
	};

	/** The largest entry of a srcset. */
	function largest( set ) {
		let best = '';
		let size = 0;
		String( set || '' ).split( /,\s+(?=\S)/ ).forEach( ( part ) => {
			const [ u, d ] = part.trim().split( /\s+/ );
			const n = parseFloat( d ) || 1;
			if ( u && n >= size ) {
				best = u;
				size = n;
			}
		} );
		return best ? abs( best ) : '';
	}

	/**
	 * A shop's resized copy → its full-size address, the server's rules (Enrichment::full_size()): Shopify, WordPress,
	 * imgix, Cloudinary, Wix, Squarespace, BigCommerce, Magento. Unknown addresses come back as they are.
	 */
	function fullSize( u ) {
		try {
			const x = new URL( u );
			const p = x.pathname;
			const drop = ( ...keys ) => keys.forEach( ( k ) => x.searchParams.delete( k ) );
			if ( /(^|\.)shopify\.com$/.test( x.hostname ) || p.includes( '/cdn/shop/' ) ) {
				drop( 'width', 'height', 'crop' );
				x.pathname = p.replace( /_(?:\d+x\d*|\d*x\d+)(?:_crop_\w+)?(?=(?:@\dx)?\.\w+$)/, '' ).replace( /_(?:pico|icon|thumb|small|compact|medium|large|grande|master|original)(?:_cropped)?(?=(?:@\dx)?\.\w+$)/, '' ); // and the older named sizes ("_small_cropped")
			} else if ( p.includes( '/wp-content/uploads/' ) ) {
				x.pathname = p.replace( /-\d{2,4}x\d{2,4}(?=\.(?:jpe?g|png|webp|gif|avif)$)/i, '' );
			} else if ( /\.imgix\.net$/.test( x.hostname ) ) {
				drop( 'w', 'h', 'fit', 'crop', 'dpr', 'q', 'max-w', 'max-h' );
			} else if ( /(^|\.)cloudinary\.com$/.test( x.hostname ) ) {
				x.pathname = p.replace( /\/upload\/(?:[a-z]{1,2}_[^/]+\/)+(?=v\d+\/)/, '/upload/' );
			} else if ( x.hostname === 'static.wixstatic.com' ) {
				x.pathname = p.replace( /^(\/media\/[^/]+)\/v1\/.*$/, '$1' );
			} else if ( /squarespace-cdn\.com$/.test( x.hostname ) ) {
				drop( 'format' );
			} else if ( p.includes( '/images/stencil/' ) ) {
				x.pathname = p.replace( /\/images\/stencil\/[^/]+\//, '/images/stencil/original/' );
			} else if ( p.includes( '/media/catalog/product/cache/' ) ) {
				x.pathname = p.replace( /\/cache\/(?:[^/]+\/)*?[0-9a-f]{32}\//, '/' );
			} else {
				return u;
			}
			return x.href;
		} catch ( e ) {
			return u;
		}
	}

	/** The biggest copy the page itself names: a zoom link, a data-zoom attribute, the largest srcset entry. */
	function bestOffered( img ) {
		const a = img.closest( 'a[href]' );
		const cands = [];
		if ( a && IMG_FILE.test( a.getAttribute( 'href' ) || '' ) ) {
			cands.push( abs( a.getAttribute( 'href' ) ) );
		}
		ZOOM_ATTRS.forEach( ( k ) => img.getAttribute( k ) && cands.push( abs( img.getAttribute( k ) ) ) );
		const pic = img.parentElement && img.parentElement.nodeName === 'PICTURE' ? img.parentElement : null;
		if ( pic ) {
			pic.querySelectorAll( 'source[srcset]' ).forEach( ( s ) => cands.push( largest( s.getAttribute( 'srcset' ) ) ) );
		}
		cands.push( largest( img.getAttribute( 'srcset' ) || img.getAttribute( 'data-srcset' ) ), img.currentSrc, img.src, abs( img.getAttribute( 'data-src' ) || '' ) );
		return cands.find( ( u ) => /^https?:\/\//i.test( u || '' ) ) || '';
	}

	/** The picture's biggest copy at its full-size address. */
	const bestSrc = ( img ) => fullSize( bestOffered( img ) );

	/** One picture whatever size or cache code its address carries. */
	const keyOf = ( u ) => {
		try {
			const x = new URL( u );
			return ( x.hostname + x.pathname.replace( /\/cache\/[0-9a-f]{32}\//, '/cache/' ) ).toLowerCase(); // Magento: one code per size
		} catch ( e ) {
			return u;
		}
	};

	window.mmptMapSelect = { GALLERY, linkInfo, linkSaid, linkFor, setKnown, iconText, withIcons, fullSize, bestOffered, bestSrc, keyOf, givesAnything, SKIP_TAGS, REQ_TEXT, MANUAL_TEXT, VIDEO_SRC, NEEDS, signature, selectorFor, matchAll, extrasOf, stepOf, narrowest, combine, combineLoose, openingTag, takenFrom, givesOf, describe, givesLabel, featureVerdicts, cardOf, LIST_PARTS, contentNames };
}() );
