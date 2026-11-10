/**
 * MMI Product Data: the panel on a maker's page, put there by the toolbar button (a second click hides it).
 *
 * The server describes the panel (ExtBridge::panel(), protocol 2): notices, sections of rows and actions, and for a
 * product that uses this page, its before/after with what can be done here. This script only draws it, as text, in a
 * closed shadow root, so the maker's styles can't reach it and its styles can't reach the page. Links open only on
 * the store's own site. Every call goes through the service worker, which holds the login.
 *
 * Actions: Store this page, Write to this product and picked pictures send the page as Chrome shows it now (pop-ups
 * you closed stay closed, script-drawn parts are there; page-copy.js). Pick a part on this page opens the live
 * mapper (mapper.js); Pick pictures and videos opens the picker (pictures.js). While a write or the picture runner
 * is under way, the panel asks again every few seconds (`poll`). Protocol 3: × on a new picture keeps it off the
 * product (veto); Link this page to a product… searches the site's brands' products (a verified page is replaced
 * only on a second click); Next product with work on this site opens that product's page in this tab, and the
 * panel opens on that product at the next click of the toolbar button; Send this site runs send.js here, its
 * progress and Stop in the panel. Protocol 4 (Site mode, plan §13): on a home, listing or unused page the server adds
 * the site overview (its page list, layouts and work); More shows it on a product page too; a layout's row opens a
 * page of it in this tab (go); "Read its page list in this browser" runs send.js for the list only. Protocol 5 (plan
 * §13 S2b): a section's `links` (Preview and Open on the store, under the product's name), and layout actions: a
 * layout part's plan asked in slices with its progress on the button, then its Write, Undo and Confirm. Protocol 8:
 * Start this site's map (a site with none), its progress in the site section until its layouts show. Protocol 6
 * (owner 2026-11-02: "have '.row' in the extension be clickable for the same feature(s)"): a row with an action and a
 * `hint` is clickable as a whole (and by keyboard) and opens picking for its part, the mapper or the picker, in place
 * of the Pick buttons.
 *
 * Protocol 9 (owner 2026-11-04: warnings "too complicated to be displayed in this manner", the commit bar, section buttons
 * and footer "have competing actions", the dots unclear): one footer holds everything that acts, the commit first and
 * the rest in More, each with a line saying what it does. A part says its state in words, and what can be put back is
 * listed under the parts. Committing goes through the review, a larger sheet beside the panel, shown and hidden from the
 * footer: what would happen to the products, outcome by outcome, each with its products and an example of one of them
 * (ExtBridge::example(): the product now and after, Preview on the store, its page). Nothing is written until Commit
 * in the review. Picking is the rows' alone: one-off pictures for one product open from inside the row's picking.
 *
 * A part's row carries its criteria's selector (`sel`): hovered or focused, what it reads is outlined on the page and
 * brought into view if it is out of sight (owner 2026-11-04: "why don't the corresponding DOM element get highlighted?").
 * A row with no pick here (none, or it finds nothing on this page) carries the two ends of the text read (`find`):
 * what holds both is outlined, so the outline shows what was read, not only what was picked.
 *
 * Open until the owner closes it (owner 2026-11-02): the panel reports open / folded to the service worker, which puts
 * it back on the next page of a site Chrome allows; "Keep it open as you browse" asks Chrome for the site.
 *
 * Nothing is stored from a bot check (the server refuses it as well), and form values never leave the page.
 */
( function () {
	'use strict';

	/** This copy can still reach the extension: a copy from before the extension reloaded itself can't. */
	const alive = () => {
		try {
			return !! chrome.runtime.id;
		} catch ( e ) {
			return false;
		}
	};
	// The build the service worker runs (it says so with every injection): a copy from another build is replaced.
	const BUILD = window.__mmiExtStart ? String( window.__mmiExtStart.build || '' ) : '';
	// The store the extension is connected to (the service worker says so with the build): { origin, base }. The live
	// mapper's bridge (mapper.js) reads it here too.
	if ( window.__mmiExtStart && window.__mmiExtStart.store ) {
		window.__mmiExtStore = window.__mmiExtStart.store;
	}
	const was = window.__mmiExtPanel;
	if ( was && was.alive() && was.build === BUILD ) {
		delete window.__mmiExtStart;
		was.toggle();
		return;
	}
	if ( was && was.dispose ) {
		was.dispose(); // the copy from before the extension updated itself
	}

	const STORE = window.__mmiExtStore || {};
	const STORE_HOST = STORE.origin ? new URL( STORE.origin ).hostname.replace( /^www\./, '' ) : 'the store';
	const HOST_TAG = 'mmi-product-data-panel';
	document.querySelectorAll( HOST_TAG ).forEach( ( e ) => e.remove() ); // an old copy's box, whatever became of its script
	const MESSAGES = {
		loading: 'Reading what the store knows about this page…',
		storing: 'Storing the page as you see it…',
		writing: 'Storing the page and starting the write…',
		undoing: 'Putting it back…',
		opening: 'Opening…',
		working: 'Working…',
		finding: 'Finding the next product…',
		loadingSite: 'Reading the site…',
		starting: 'Starting…',
		keepOpen: 'Keep it open as you browse',
		going: ' Opening its page: click MMI Product Data in the toolbar there to carry on.',
		vetoing: 'Keeping it off…',
		searching: 'Searching…',
		noMatch: 'No product of this site’s brands matches.',
		linking: 'Linking…',
		stop: 'Stop',
		searchLabel: 'Find a product',
		wall: 'This is the site’s bot check, not its page, so nothing was sent. If you get through it yourself, try again.',
		stored: 'Stored under %n address(es): %t characters of text and %i pictures the readers can use.',
		kept: 'Not stored: the copy the store has reads more (%t characters, %i pictures) than this page as shown. Close anything covering the page or open its hidden parts, then store it again.',
		checked: 'Checked, nothing stored: it would be stored under %n address(es), with %t characters of text and %i pictures.',
		writeStarted: 'Writing to this product. This panel says when it’s done.',
		connect: 'Connect this browser',
		retry: 'Try again',
		closePanel: 'Hide the panel',
		minimize: 'Fold the panel',
		open: 'Product Data',
		failed: 'Something went wrong.',
		reloaded: 'The extension was reloaded. Reload this page, then click the button again.',
		commitHead: [ 'Review and commit the 1 ticked part for:', 'Review and commit the %n ticked parts for:' ],
		commitNone: 'Tick the parts to commit',
		reviewProduct: 'This product',
		reviewLayout: '%l’s %n products',
		reviewSite: 'All %n on this site',
		reviewOf: 'Review what a commit does to: %s',
		more: 'More actions',
		keyToggle: 'What the labels mean',
		leftOut: 'Left out',
		leftOutHelp: 'Unticked, so a commit doesn’t touch it. Tick it to include it.',
		undoHead: 'Can be put back',
		tickTitle: 'Include this part in the commit',
		sheetLabel: 'Review',
		sheetProduct: 'What committing does to this product',
		sheetLayout: 'What committing does to %l’s %n products',
		sheetSite: 'What committing does to the site’s %n products',
		theSite: 'the site',
		proposed: 'Proposed:',
		proposalsHead: 'Proposed pages waiting (%n)',
		proposalNow: 'Now: %t',
		proposalNoPage: 'No page yet',
		reject: 'Reject',
		sheetSub: 'Nothing has been written. This only shows what Commit would do.',
		hide: 'Hide the review',
		close: 'Close',
		cancel: 'Cancel',
		looked: [ 'Looked at 1 minute ago.', 'Looked at %n minutes ago.' ],
		lookAgain: 'Look again',
		lookingPart: 'Looking at %p: %n of %t products…',
		lookingStart: 'Looking at %p…',
		lookingSlow: 'This can take a few minutes the first time. You can hide the review and keep working: it goes on.',
		outOf: '%n of %t products change',
		toneChange: 'Will change',
		toneLook: 'Worth a look',
		toneSame: 'No change',
		exampleOne: 'See an example',
		showProducts: 'List the %n products',
		hideProducts: 'Hide the list',
		showAll: 'Show all %n',
		seeIt: 'See it',
		hideIt: 'Hide it',
		stays: 'Stays as it is',
		nowChars: 'Now · %n characters',
		afterChars: 'After the commit · %n characters',
		nowCount: 'Now · %n',
		afterCount: 'After the commit · %n',
		none: 'Nothing',
		changedKey: 'Highlighted text differs between the two.',
		willWrite: 'A commit writes this.',
		willReview: 'A commit sends this to Review. The product doesn’t change until it’s approved.',
		willNot: 'A commit leaves this as it is.',
		previewOn: `Preview on ${ STORE_HOST }`,
		openOn: `Open on ${ STORE_HOST }`,
		openPage: 'Open its page',
		notIncluded: 'Not in this commit: %p. They have no pick of %l’s own, so they can’t go to its products. Click the part and save a pick for %l.',
		noneToCommit: 'None of the ticked parts has a pick of %l’s own, so nothing can go to its products. Click a part and save a pick for %l.',
		commitProduct: 'Commit to this product',
		commitLayout: [ 'Commit to 1 product', 'Commit to %n products' ],
		commitNoChange: 'Nothing to commit',
		commitNote: 'Afterwards, Undo in the panel puts back what it changed.',
		commitNoteProduct: 'Commit stores this page as you see it, then writes these parts. Afterwards, Undo in the panel puts back what it changed.',
		typeIt: 'Type it instead',
		itsRight: 'It’s right',
		openOnly: 'Open',
		notIts: 'Not its page…',
		unsure: 'Undo',
		confirmed: 'You said this is its page.',
		noPublish: 'Maker doesn’t publish it',
		noPublishHelp: 'Removes its vendor URL. It keeps what it has, and nothing reads a page for it until a person sets one.',
		noPublishGo: 'Confirm: remove its vendor URL',
		rightHead: 'The right page',
		likely: 'Likely:',
		usePage: 'Use this page',
		use: 'Use',
		paste: 'Or paste its address',
		pasteFirst: 'Paste its address',
		findIt: 'Or find it on the site ↗',
		findHelp: 'A Link this URL button follows you there.',
		noPage: 'No page exists?',
		handOn: 'Finding the page for %t',
		handUse: 'Link this URL',
		heldWait: `Getting it from ${ STORE_HOST }…`,
		heldNoPicture: 'No picture yet',
		heldNoText: 'No description yet.',
		heldFail: `${ STORE_HOST } didn’t answer. Hover again to retry.`,
		handOff: 'Stop',
		handOpened: 'Opened in a new tab. If the panel isn’t there, click MMI Product Data in the toolbar.',
		openIt: 'Open ↗',
		changeBrand: 'Change brand',
		brandHead: 'Which brand is it?',
		brandFind: 'Type a brand’s name',
		brandNone: 'No brand has that name. Brands are added on the Product Data page.',
		siteHead: 'Its maker’s site',
		sitePaste: 'Paste the site’s address',
		noSite: 'This brand has no maker site',
		noSiteGo: 'Click again to confirm',
		addSite: 'Add %h as %b’s site',
		linkedUndo: 'Undo',
		linkedOpen: 'Open it to commit ↗',
		putBack: 'Put it back',
		sheetLinks: 'Are this site’s products linked to the right pages?',
		linksSub: 'Compared by title and address. It’s evidence, not a verdict: a right page with a short title rates low too.',
		linksCounts: '%l to check of %t products. %c confirmed by you.',
		linksWaiting: ' %w more are being compared: look again in a minute.',
		linksNone: 'Nothing doubtful on this site.',
		pageSays: 'Its page: “%t”',
		pageIs: 'Its page says “%t”.',
		save: 'Save',
		saving: 'Saving…',
		goesLive: 'Committing also puts this pick in use on %l’s pages from now on, on products that don’t change today too.',
		committing: 'Committing %p…',
	};
	const CSS = `
		:host { all: initial; }
		* { box-sizing: border-box; }
		.p { position: fixed; top: 12px; right: 12px; bottom: 12px; width: 380px; display: flex; flex-direction: column; background: #fff; color: #1d2327; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 8px 28px rgba(0,0,0,.18); font: 13px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; z-index: 2147483647; overflow: hidden; }
		.p[hidden], .pill[hidden] { display: none; }
		.peek { position: fixed; inset: 0; pointer-events: none; z-index: 2147483646; }
		.peek i { position: fixed; box-sizing: border-box; border: 3px solid #2f7de1; border-radius: 2px; background: rgba(47,125,225,.12); }
		.peek i[hidden] { display: none; }
		.hd { display: flex; align-items: flex-start; gap: 8px; padding: 12px 12px 10px 14px; border-bottom: 1px solid #dcdcde; background: #f6f7f7; }
		.hd .t { flex: 1; min-width: 0; }
		.hd h1 { margin: 0; font-size: 14px; font-weight: 600; overflow-wrap: anywhere; }
		.hd h1 a { color: inherit; text-decoration: none; }
		.hd h1 a:hover { color: #2271b1; text-decoration: underline; }
		.hd .s { margin-top: 2px; color: #646970; font-size: 12px; overflow-wrap: anywhere; }
		.ib { flex: none; width: 26px; height: 26px; border: 1px solid transparent; border-radius: 4px; background: transparent; color: #50575e; font-size: 16px; line-height: 1; cursor: pointer; }
		.ib:hover, .ib:focus-visible { border-color: #c3c4c7; background: #fff; outline: none; }
		.bd { flex: 1; overflow-y: auto; padding: 12px 14px; }
		.n { margin: 0 0 10px; padding: 8px 10px; border-left: 4px solid #72aee6; background: #f0f6fc; }
		.n.warn { border-color: #dba617; background: #fcf9e8; }
		.n.error { border-color: #d63638; background: #fcf0f1; }
		.n.ok { border-color: #00a32a; background: #edfaef; }
		.n button { margin-top: 6px; }
		.n .nl { display: flex; flex-wrap: wrap; gap: 0 8px; }
		.sec { margin: 0 0 14px; }
		.sec.product { padding: 10px 12px; border: 1px solid #c3c4c7; border-radius: 6px; background: #fbfbfc; }
		h2 { margin: 0 0 6px; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: .04em; color: #50575e; }
		.sec.product h2 { text-transform: none; letter-spacing: 0; font-size: 13.5px; color: #1d2327; }
		h2 a { color: #2271b1; text-decoration: none; }
		h2 a:hover { text-decoration: underline; }
		.lks { display: flex; flex-wrap: wrap; gap: 2px 14px; margin: -2px 0 6px; font-size: 12px; }
		.lks a { color: #2271b1; text-decoration: none; }
		.lks a:hover { text-decoration: underline; }
		.row { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px solid #f0f0f1; }
		.row:last-of-type { border-bottom: 0; }
		.lh { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
		.chip { flex: none; padding: 1px 8px; border-radius: 10px; background: #f0f0f1; color: #50575e; font-size: 11px; font-weight: 600; line-height: 16px; white-space: nowrap; }
		.chip.change { background: #d8ebfb; color: #0a4b78; }
		.chip.ok, .chip.same { background: #e3f4e6; color: #00591a; }
		.chip.missing { background: #fbe3e4; color: #a12224; }
		.chip.review, .chip.look { background: #fbf0c9; color: #6d5100; }
		.row > div { min-width: 0; flex: 1; }
		.row .l { font-weight: 600; overflow-wrap: anywhere; }
		.row a.l, .row button.l { color: #2271b1; text-decoration: none; }
		.row button.l { padding: 0; border: 0; background: none; font: inherit; font-weight: 600; text-align: left; cursor: pointer; }
		.row a.l:hover, .row button.l:hover { text-decoration: underline; }
		.row .v { color: #50575e; font-size: 12px; overflow-wrap: anywhere; }
		.row.pick, .row.shows { margin: 0 -6px; padding: 6px; border-radius: 4px; }
		.row.pick { cursor: pointer; }
		.row.pick:hover, .row.pick:focus-visible, .row.shows:hover { background: #f0f6fc; outline: none; box-shadow: inset 0 0 0 1px #72aee6; }
		.row.pick .go { display: block; margin-top: 2px; color: #2271b1; font-size: 12px; }
		.row .why { margin-top: 2px; color: #50575e; font-size: 12px; }
		.row.pick:hover .go, .row.pick:focus-visible .go { text-decoration: underline; }
		.th { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
		.th img { width: 48px; height: 48px; object-fit: contain; background: #fff; border: 1px solid #dcdcde; border-radius: 3px; }
		.more { padding: 6px 0 0; color: #50575e; font-size: 12px; }
		.row .ck { flex: none; margin: 3px 0 0; width: 15px; height: 15px; cursor: pointer; accent-color: #2271b1; }
		.row .cr { margin-top: 2px; color: #646970; font-size: 11.5px; overflow-wrap: anywhere; }
		.lk { padding: 0; border: 0; background: none; color: #2271b1; font: inherit; font-size: 12px; cursor: pointer; }
		.lk:hover { text-decoration: underline; }
		.ed { margin-top: 2px; }
		.ma { margin-top: 3px; }
		.ma .mb { display: flex; gap: 14px; }
		.ma .edf { margin-top: 6px; }
		.ma .edf button.b { margin-top: 6px; }
		.ma .edf { padding: 8px 10px; border: 1px solid #dcdcde; border-radius: 6px; background: #fff; }
		.ma h4 { margin: 0 0 4px; font-size: 12px; font-weight: 600; }
		.ma .lp { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 4px 10px; padding: 3px 0; font-size: 12px; }
		.ma .lw { min-width: 0; overflow-wrap: anywhere; }
		.ma .lw a { color: #2271b1; text-decoration: none; white-space: nowrap; }
		.ma .edf .lp button.b, .ma .pa button.b { margin-top: 0; }
		.ma .pl2 { margin: 8px 0 3px; color: #50575e; font-size: 12px; }
		.ma .pa { display: flex; gap: 6px; margin-bottom: 6px; }
		.ma .pa input { flex: 1; min-width: 0; min-height: 30px; padding: 4px 8px; border: 1px solid #8c8f94; border-radius: 4px; font: inherit; font-size: 12px; }
		.hand { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 10px; margin: 0 0 12px; padding: 10px 12px; border: 1px solid #2271b1; border-radius: 6px; background: #f0f6fc; }
		.hand b { display: block; width: fit-content; font-weight: 600; cursor: help; text-decoration: underline dotted #2271b1; text-underline-offset: 3px; }
		.hand b:focus-visible { outline: 2px solid #2271b1; outline-offset: 2px; }
		.held { position: fixed; right: 404px; width: min(320px, calc(100vw - 428px)); max-height: calc(100vh - 24px); box-sizing: border-box; overflow-y: auto; padding: 12px; background: #fff; color: #1d2327; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 8px 28px rgba(0,0,0,.18); font: 13px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; z-index: 2147483647; }
		.held img, .held .none { display: block; box-sizing: border-box; width: 100%; height: 180px; margin-bottom: 8px; object-fit: contain; background: #f6f7f7; border-radius: 4px; }
		.held .none { padding-top: 80px; color: #646970; font-size: 12px; text-align: center; }
		.held .t { font-weight: 600; overflow-wrap: anywhere; }
		.held .v { margin-top: 4px; color: #50575e; font-size: 12px; overflow-wrap: anywhere; }
		.held a { display: inline-block; margin-top: 8px; color: #2271b1; font-size: 12px; text-decoration: none; }
		.held a:hover { text-decoration: underline; }
		.hand > div { flex: 1 1 100%; min-width: 0; font-size: 12px; overflow-wrap: anywhere; }
		.ph3 { margin: 16px 0 4px; font-size: 15px; font-weight: 600; }
		.ma.mb { display: flex; gap: 14px; }
		.lr { padding: 10px 0; border-bottom: 1px solid #f0f0f1; }
		.lr .lt { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; }
		.lr b { font-size: 14px; font-weight: 600; }
		.lr .v { margin-top: 2px; color: #3c434a; font-size: 13px; overflow-wrap: anywhere; }
		.lr .xl { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 3px; font-size: 13px; }
		.lr .xl a { color: #2271b1; text-decoration: none; }
		.edf[hidden], .lk[hidden] { display: none; }
		.ed textarea { display: block; width: 100%; margin: 4px 0 6px; padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 4px; color: #1d2327; font: inherit; resize: vertical; }
		.ed .row2 { display: flex; gap: 6px; }
		.key { margin-top: 6px; font-size: 12px; }
		.key dl { margin: 6px 0 0; }
		.key dt { margin-top: 6px; }
		.key dd { margin: 2px 0 0; color: #50575e; }
		.un { margin-top: 10px; padding-top: 8px; border-top: 1px solid #dcdcde; }
		.un h3 { margin: 0 0 4px; font-size: 12px; font-weight: 600; color: #50575e; }
		.un div { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; padding: 3px 0; font-size: 12px; }
		.un .lk { flex: none; }
		.n ul { margin: 6px 0 0 18px; padding: 0; }
		.n li { margin: 0 0 4px; }
		.acts { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
		.th span { position: relative; display: inline-block; }
		.th button { position: absolute; top: -6px; right: -6px; width: 18px; height: 18px; padding: 0; border: 0; border-radius: 50%; background: #d63638; color: #fff; font-size: 12px; line-height: 18px; cursor: pointer; }
		.prog { display: flex; align-items: center; gap: 8px; padding: 8px 14px; border-bottom: 1px solid #dcdcde; background: #f0f6fc; font-size: 12.5px; }
		.prog.ok { background: #edfaef; } .prog.warn { background: #fcf9e8; } .prog.error { background: #fcf0f1; }
		.prog[hidden] { display: none; }
		.prog span.t { flex: 1; }
		.find { margin: 0 0 12px; padding: 10px 12px; border: 1px solid #c3c4c7; border-radius: 6px; }
		.find input { width: 100%; min-height: 30px; padding: 4px 8px; border: 1px solid #8c8f94; border-radius: 4px; font: inherit; }
		.find input:focus { outline: 2px solid #2271b1; outline-offset: 0; }
		.ft { padding: 10px 14px; border-top: 1px solid #dcdcde; background: #f6f7f7; }
		.ft:empty { display: none; }
		.ft .ct { margin-bottom: 6px; font-size: 12px; font-weight: 600; }
		.ft .row2 { display: flex; flex-wrap: wrap; gap: 8px; }
		.ft .row2 + .row2, .ft .cm + .row2 { margin-top: 8px; }
		.ft button.b[aria-pressed="true"] { background: #135e96; border-color: #135e96; color: #fff; }
		.ml { max-height: 40vh; margin: 0 0 8px; overflow-y: auto; border: 1px solid #dcdcde; border-radius: 6px; background: #fff; }
		.ml button { display: block; width: 100%; padding: 7px 10px; border: 0; border-bottom: 1px solid #f0f0f1; background: none; color: #1d2327; font: inherit; text-align: left; cursor: pointer; }
		.ml button:last-child { border-bottom: 0; }
		.ml button:hover { background: #f0f6fc; }
		.ml b { display: block; font-weight: 600; color: #2271b1; }
		.ml span { display: block; color: #50575e; font-size: 12px; }
		.sh { position: fixed; top: 12px; right: 404px; bottom: 12px; width: min(780px, calc(100vw - 428px)); display: flex; flex-direction: column; background: #fff; color: #1d2327; border: 1px solid #c3c4c7; border-radius: 8px; box-shadow: 0 8px 28px rgba(0,0,0,.18); font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; z-index: 2147483647; overflow: hidden; }
		.sh[hidden] { display: none; }
		.sh:focus { outline: none; }
		@media (max-width: 860px) { .sh { right: 12px; width: calc(100vw - 24px); } }
		.shh { display: flex; align-items: flex-start; gap: 8px; padding: 14px 16px 12px; border-bottom: 1px solid #dcdcde; background: #f6f7f7; }
		.shh .t { flex: 1; min-width: 0; }
		.shh h2 { margin: 0; font-size: 16px; font-weight: 600; letter-spacing: 0; text-transform: none; color: #1d2327; }
		.shh .s { margin-top: 2px; color: #50575e; font-size: 13px; }
		.shb { flex: 1; overflow-y: auto; padding: 14px 16px; }
		.shf { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 12px 16px; border-top: 1px solid #dcdcde; background: #f6f7f7; }
		.shf .s { flex: 1; min-width: 180px; color: #50575e; font-size: 12.5px; }
		.pc { margin: 0 0 18px; }
		.pc > h3 { display: flex; align-items: baseline; justify-content: space-between; gap: 10px; margin: 0 0 8px; font-size: 15px; font-weight: 600; }
		.pc > h3 span { color: #50575e; font-size: 13px; font-weight: 400; }
		.pc > p.gl { margin: 0 0 8px; color: #3c434a; font-size: 13px; }
		.gr { margin: 0 0 8px; padding: 10px 12px; border: 1px solid #dcdcde; border-left-width: 4px; border-radius: 6px; }
		.gr.change { border-left-color: #2271b1; } .gr.look { border-left-color: #dba617; background: #fffdf5; } .gr.same { border-left-color: #8fbf9c; }
		.gr .gh { display: flex; flex-wrap: wrap; align-items: baseline; gap: 4px 10px; }
		.gr .gh b { font-size: 14px; font-weight: 600; }
		.gr p { margin: 3px 0 0; color: #3c434a; font-size: 13px; }
		.gr p.fix { color: #1d2327; }
		.gr .ga { display: flex; flex-wrap: wrap; gap: 4px 16px; margin-top: 6px; }
		.gr .lk, .pl .lk { font-size: 13px; }
		.pl { margin: 8px 0 0; padding: 0; list-style: none; border-top: 1px solid #f0f0f1; }
		.pl li { padding: 5px 0; border-bottom: 1px solid #f0f0f1; }
		.pl .pt { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; font-size: 13px; }
		.ex { margin: 8px 0 4px; padding: 10px 12px; border-radius: 6px; background: #f6f7f7; font-size: 13px; }
		.ex .say { font-weight: 600; }
		.ex .mt { margin: 4px 0; font-size: 13px; }
		.ex .xl { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 4px 0 8px; font-size: 13px; }
		.ex .xl a { color: #2271b1; text-decoration: none; }
		.ex .xl a:hover { text-decoration: underline; }
		.cols { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
		@media (max-width: 860px) { .cols { grid-template-columns: 1fr; } }
		.col { min-width: 0; padding: 8px 10px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; }
		.col h4 { margin: 0 0 4px; color: #50575e; font-size: 12px; font-weight: 600; }
		.col .tx { max-height: 260px; overflow-y: auto; overflow-wrap: anywhere; }
		.col .tx mark { background: #fff2b8; color: inherit; }
		.col ul { max-height: 260px; margin: 0; padding: 0 0 0 16px; overflow-y: auto; overflow-wrap: anywhere; }
		.col .ph { display: flex; flex-wrap: wrap; gap: 6px; max-height: 260px; overflow-y: auto; }
		.col figure { position: relative; width: 84px; margin: 0; }
		.col img { display: block; width: 84px; height: 84px; object-fit: contain; border: 1px solid #dcdcde; border-radius: 3px; background: #fff; }
		.col figcaption { margin-top: 2px; color: #0a4b78; font-size: 11px; font-weight: 600; line-height: 1.2; }
		.ex .ky { margin-top: 6px; color: #50575e; font-size: 12px; }
		button.b { min-height: 30px; padding: 0 12px; border: 1px solid #2271b1; border-radius: 4px; background: #fff; color: #2271b1; font: inherit; font-weight: 500; cursor: pointer; }
		button.b.pr { background: #2271b1; color: #fff; }
		button.b:hover { background: #f0f6fc; } button.b.pr:hover { background: #135e96; }
		button.b:disabled { opacity: .6; cursor: default; }
		button:focus-visible { outline: 2px solid #2271b1; outline-offset: 1px; }
		.spin { display: inline-block; width: 12px; height: 12px; margin-right: 6px; vertical-align: -2px; border: 2px solid #c3c4c7; border-top-color: #2271b1; border-radius: 50%; animation: r .8s linear infinite; }
		@keyframes r { to { transform: rotate(360deg); } }
		.pill { position: fixed; right: 12px; bottom: 12px; z-index: 2147483647; padding: 8px 14px; border: 0; border-radius: 18px; background: #1d2327; color: #fff; font: 600 13px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; box-shadow: 0 4px 14px rgba(0,0,0,.25); cursor: pointer; }
	`;

	/* ── The panel's box ───────────────────────────────────────────────── */

	const host = document.createElement( HOST_TAG );
	host.setAttribute( 'style', 'all: initial;' ); // the page's own rules for unknown elements don't move the panel
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
	const style = el( 'style', '', CSS );
	const box = el( 'div', 'p' );
	box.setAttribute( 'role', 'complementary' );
	box.setAttribute( 'aria-label', 'MMI Product Data' );
	const head = el( 'div', 'hd' );
	const titles = el( 'div', 't' );
	const h1 = el( 'h1', '', 'Product Data' );
	const sub = el( 'div', 's', location.hostname.replace( /^www\./, '' ) + location.pathname );
	titles.append( h1, sub );
	const fold = el( 'button', 'ib', '–' );
	fold.title = MESSAGES.minimize;
	fold.setAttribute( 'aria-label', MESSAGES.minimize );
	const shut = el( 'button', 'ib', '×' );
	shut.title = MESSAGES.closePanel;
	shut.setAttribute( 'aria-label', MESSAGES.closePanel );
	head.append( titles, fold, shut );
	const body = el( 'div', 'bd' );
	body.setAttribute( 'aria-live', 'polite' );
	const foot = el( 'div', 'ft' );
	const prog = el( 'div', 'prog' );
	prog.setAttribute( 'role', 'status' );
	prog.hidden = true;
	box.append( head, prog, body, foot );
	const sheet = el( 'aside', 'sh' );
	sheet.setAttribute( 'aria-label', MESSAGES.sheetLabel );
	sheet.tabIndex = -1;
	sheet.hidden = true;
	const pill = el( 'button', 'pill', MESSAGES.open );
	pill.hidden = true;
	const layer = el( 'div', 'peek' );
	const PEEK = { max: 40, waitMs: 500, words: 50 };
	let peeking = [];
	let peekFrame = 0;
	let peekWait = 0;
	root.append( style, layer, sheet, box, pill );
	document.documentElement.appendChild( host );

	const state = { product: 0, poll: 0, timer: 0, site: false, allowed: true, href: location.href, ticks: {}, ticksFor: 0, more: false, key: false, shown: '', reviews: {}, say: null };

	/** Open or closed, folded or not: the service worker keeps it for this tab, and puts the panel back on the next page. */
	const report = () => chrome.runtime.sendMessage( { type: 'panelstate', open: host.style.display !== 'none', folded: box.hidden } ).catch( () => {} );
	const show = ( on ) => {
		host.style.display = on ? '' : 'none';
		if ( ! on ) {
			stopPolling();
		}
	};
	const folded = ( on ) => {
		box.hidden = on;
		pill.hidden = ! on;
		peekOff();
		if ( on ) {
			hideReview();
		}
	};
	fold.addEventListener( 'click', () => {
		folded( true );
		report();
	} );
	pill.addEventListener( 'click', () => {
		folded( false );
		report();
	} );
	shut.addEventListener( 'click', () => {
		show( false );
		report();
	} );
	if ( window.__mmiExtStart ) { // put back by the service worker on a new page
		folded( !! window.__mmiExtStart.folded );
		delete window.__mmiExtStart;
	}
	/** A page that changes its address without loading (a shop's product switcher): the panel follows it. */
	const follow = window.setInterval( () => {
		if ( ! alive() ) {
			window.clearInterval( follow ); // an old copy after the extension reloaded itself
			return;
		}
		if ( location.href !== state.href ) {
			state.href = location.href;
			state.look = false;
			state.base = '';
			state.product = 0;
			forgetReviews();
			if ( host.style.display !== 'none' ) {
				load( true );
			}
		}
	}, 1000 );

	window.__mmiExtPanel = {
		alive,
		build: BUILD,
		/** Replaced by a newer build's copy: stops and takes its box away. */
		dispose() {
			window.clearInterval( follow );
			host.remove();
		},
		toggle() {
			const hiddenNow = host.style.display === 'none';
			show( hiddenNow );
			if ( hiddenNow ) {
				folded( false );
				load();
			}
			report();
		},
		/** The live mapper saved a part: what it gives the product now. */
		refresh() {
			forgetReviews();
			load( true );
		},
		/** The mapper handed a part over for one-off picks, for this product only. */
		pick( part ) {
			const f = window.__mmiExtPickFor || {};
			act( { kind: 'pictures', part, product: f.product, title: f.title }, el( 'button' ) );
		},
		/** The mapper or the picker closed: the panel again. */
		unfold() {
			show( true );
			folded( false );
			load( true );
		},
		/** The picker sent its picks: the server's answer and the panel it drew. */
		answer( res ) {
			show( true );
			folded( false );
			answered( res );
		},
		product: () => state.product,
		/** Work running on this page (Send this site): its line above the panel, with Stop while it runs. */
		progress( text, kind, onStop ) {
			show( true );
			const t = el( 'span', 't', text );
			const parts = kind === 'info' ? [ el( 'span', 'spin' ), t ] : [ t ];
			if ( onStop ) {
				const b = el( 'button', 'b', MESSAGES.stop );
				b.addEventListener( 'click', () => {
					b.disabled = true;
					onStop();
				} );
				parts.push( b );
			}
			prog.className = 'prog ' + ( kind || '' );
			prog.replaceChildren( ...parts );
			prog.hidden = false;
		},
	};

	/* ── Committing (protocol 7) ────────────────────────────────────────── */

	/** A part's tick: the person's, else the server's default (on where this product gets a change). */
	function ticked( key, dflt ) {
		return Object.prototype.hasOwnProperty.call( state.ticks, key ) ? state.ticks[ key ] : ( state.ticks[ key ] = dflt );
	}

	/** The page a product's parts come from: this one, or on our own product page, its linked page (panel.base). */
	const at = () => state.base || location.href;

	const tickedParts = ( c ) => Object.keys( c.parts || {} ).filter( ( k ) => state.ticks[ k ] );

	/** Words for a count: [ one, many ]. */
	const counted = ( words, n ) => words[ n === 1 ? 0 : 1 ].replace( '%n', Number( n ).toLocaleString() );

	/** The footer's commit: how many parts are ticked, and a review for this product or for the layout's products. */
	function commitBlock( c ) {
		const box = el( 'div', 'cm' );
		const head = el( 'div', 'ct' );
		const acts = el( 'div', 'row2' );
		const opener = ( scope, label ) => { // filled only while its review is open: that is the state to read
			const b = el( 'button', 'b', label );
			b.type = 'button';
			b.dataset.scope = scope;
			b.setAttribute( 'aria-pressed', String( state.shown === scope ) );
			b.setAttribute( 'aria-label', MESSAGES.reviewOf.replace( '%s', label ) );
			b.addEventListener( 'click', () => toggleReview( scope, c ) );
			acts.append( b );
			return b;
		};
		const buttons = [ opener( 'product', MESSAGES.reviewProduct ) ];
		if ( c.layout && c.layout.products > 0 ) {
			buttons.push( opener( 'layout', MESSAGES.reviewLayout.replace( '%l', c.layout.name ).replace( '%n', c.layout.products ) ) );
		}
		if ( c.site && c.site.products > 1 ) {
			buttons.push( opener( 'site', MESSAGES.reviewSite.replace( '%n', c.site.products ) ) );
		}
		const count = () => {
			const n = tickedParts( c ).length;
			head.textContent = n ? counted( MESSAGES.commitHead, n ) : MESSAGES.commitNone;
			buttons.forEach( ( b ) => {
				b.disabled = ! n;
			} );
		};
		state.say = () => {
			count();
			hideReview(); // what it showed is no longer what's ticked
		};
		count();
		box.append( head, acts );
		return box;
	}

	/* ── The review (protocol 9) ────────────────────────────────────────── */

	/** The footer's buttons show which review is open. */
	function markReview() {
		foot.querySelectorAll( '.cm button' ).forEach( ( b ) => b.setAttribute( 'aria-pressed', String( state.shown === b.dataset.scope ) ) );
	}

	function hideReview() {
		state.shown = '';
		sheet.hidden = true;
		markReview();
	}

	/** What the reviews showed no longer holds: a pick was saved, the page changed, a commit went through. */
	function forgetReviews() {
		state.reviews = {};
		hideReview();
	}

	/** Shows or hides a review; a look under way goes on while it's hidden, and is shown again as it stands. */
	function toggleReview( scope, c ) {
		if ( state.shown === scope ) {
			hideReview();
			return;
		}
		const parts = tickedParts( c );
		const key = [ state.product, parts.join() ].join( '|' );
		if ( ! state.reviews[ scope ] || state.reviews[ scope ].key !== key ) {
			state.reviews[ scope ] = { scope, key, c, parts, plans: [], data: null, busy: ' ', error: '', open: {}, examples: {}, all: {} };
			lookFor( state.reviews[ scope ], false );
		}
		showReview( scope );
	}

	function showReview( scope ) {
		state.shown = scope;
		sheet.hidden = false;
		drawReview();
		markReview();
		sheet.focus();
	}

	/** A review started from the layout section: one part, no product in focus. */
	function reviewPart( a ) {
		const c = { product: state.product, writes: [], parts: { [ a.part ]: { label: a.part_label || '', layout: true } }, layout: { name: a.name || '', products: a.products || 0 } };
		state.reviews.layout = { scope: 'layout', key: '', c, parts: [ a.part ], plans: [], data: null, busy: ' ', error: '', open: {}, examples: {}, all: {} };
		lookFor( state.reviews.layout, false );
		showReview( 'layout' );
	}

	function lookFor( r, fresh ) {
		return wide( r ) ? lookLayout( r, fresh ) : lookProduct( r );
	}

	/** A review over many products: the layout's, or the whole site's. */
	const wide = ( r ) => r.scope === 'layout' || r.scope === 'site';
	const askType = ( r ) => ( r.scope === 'site' ? 'sitepart' : 'layout' );
	const whose = ( r ) => ( r.scope === 'site' ? MESSAGES.theSite : r.c.layout.name );

	/** This product: every ticked part, now and after, in one question. */
	async function lookProduct( r ) {
		const res = await ask( { type: 'example', url: at(), product: r.c.product, parts: r.parts, scope: 'product' } );
		r.busy = '';
		if ( res && res.ok ) {
			r.data = res.data;
		} else {
			r.error = ( res && res.data && res.data.message ) || MESSAGES.failed;
		}
		redraw( r );
	}

	/** The layout's products: each ticked part with a pick of the layout's own, asked in slices with the count showing. */
	async function lookLayout( r, fresh ) {
		const c = r.c;
		const parts = r.parts.filter( ( k ) => c.parts[ k ] && c.parts[ k ][ r.scope ] );
		r.left = r.parts.filter( ( k ) => ! parts.includes( k ) ).map( ( k ) => c.parts[ k ].label );
		r.plans = [];
		r.error = '';
		for ( const part of parts ) {
			r.busy = MESSAGES.lookingStart.replace( '%p', c.parts[ part ].label );
			redraw( r );
			for ( let offset = 0; ; ) {
				const res = await ask( { type: askType( r ), url: location.href, op: 'plan', part, offset, product: state.product, fresh: fresh ? 1 : 0 } );
				const d = ( res && res.data ) || {};
				if ( state.reviews[ r.scope ] !== r ) {
					return; // ticks or picks changed: another review took over
				}
				if ( ! res || ! res.ok ) {
					r.busy = '';
					r.error = d.message || MESSAGES.failed;
					redraw( r );
					return;
				}
				if ( typeof d.next === 'number' && d.next > offset ) {
					offset = d.next;
					r.busy = MESSAGES.lookingPart.replace( '%p', c.parts[ part ].label ).replace( '%n', d.done ).replace( '%t', d.total );
					redraw( r );
					continue;
				}
				r.plans.push( d.review );
				break;
			}
		}
		r.busy = '';
		redraw( r );
	}

	/** The site's products whose vendor page may be the wrong one. */
	async function lookLinks( r ) {
		const res = await ask( { type: 'mapping', url: location.href, op: 'list' } );
		r.busy = '';
		if ( res && res.ok ) {
			r.data = res.data;
		} else {
			r.error = ( res && res.data && res.data.message ) || MESSAGES.failed;
		}
		redraw( r );
	}

	/** A product on the check: it beside its page, why it was listed, and what can be decided. */
	function linkRow( r, x ) {
		const row = el( 'div', 'lr' );
		const top = el( 'div', 'lt' );
		top.append( el( 'b', '', x.title ), el( 'span', 'chip ' + x.kind, x.word ) );
		row.append( top, el( 'div', 'v', MESSAGES.pageSays.replace( '%t', x.page ) ), el( 'div', 'v', x.help ) );
		const links = el( 'div', 'xl' );
		if ( sameSite( x.url ) ) {
			links.append( link( 'a', '', MESSAGES.openPage + ' ↗', x.url ) );
		}
		const view = ownLink( x.view || '' );
		if ( view ) {
			links.append( link( 'a', '', MESSAGES.openOn + ' ↗', view ) );
		}
		row.append( links, matchActions( { product: x.id, title: x.title, ok: false, likely: x.likely }, false, ( how ) => {
			if ( how === 'right' ) {
				row.remove();
			}
			r.data.listed = Math.max( 0, r.data.listed - 1 );
		} ) );
		return row;
	}

	/** A page the finder proposes for a product: it beside what the product has now, decided here. */
	function proposalRow( x ) {
		const row = el( 'div', 'lr' );
		const top = el( 'div', 'lt' );
		top.append( el( 'b', '', x.title ), el( 'span', 'chip ' + x.kind, x.word ) );
		row.append( top, el( 'div', 'v', MESSAGES.proposed + ' ' + x.short + ' · “' + x.page + '”' ), el( 'div', 'v', x.now ? MESSAGES.proposalNow.replace( '%t', x.now ) : MESSAGES.proposalNoPage ) );
		const links = el( 'div', 'xl' );
		if ( sameSite( x.url ) ) {
			links.append( link( 'a', '', MESSAGES.openPage + ' ↗', x.url ) );
		}
		row.append( links, proposalActions( { product: x.id }, false ) );
		return row;
	}

	/** Use a proposal or reject it. Each answers in place, with the way back. */
	function proposalActions( p, inPanel ) {
		const box = el( 'div', 'ma mb' );
		const tell = async ( op, b ) => {
			const res = await working( b, MESSAGES.saving, () => ask( { type: 'mapping', url: location.href, op, product: p.product, panel: inPanel ? 1 : 0 } ) );
			if ( inPanel || ! res || ! res.ok ) {
				answered( res );
			}
			return res;
		};
		const act2 = ( text, op, back ) => {
			const b = el( 'button', 'lk', text );
			b.type = 'button';
			b.addEventListener( 'click', async () => {
				const res = await tell( op, b );
				if ( res && res.ok && ! inPanel ) {
					const line = el( 'div', 'v', res.data.message || '' );
					const u = el( 'button', 'lk', MESSAGES.linkedUndo );
					u.type = 'button';
					u.addEventListener( 'click', async () => {
						const again = await tell( back, u );
						if ( again && again.ok ) {
							box.replaceChildren( el( 'div', 'v', again.data.message || '' ) );
						}
					} );
					line.append( ' ', u );
					box.replaceChildren( line );
				}
			} );
			return b;
		};
		box.append( act2( MESSAGES.usePage, 'accept', 'undo' ), act2( MESSAGES.reject, 'reject', 'reset' ) );
		return box;
	}

	/** Draws the review again if it's the one on show. */
	function redraw( r ) {
		if ( state.reviews[ state.shown ] === r ) {
			drawReview();
		}
	}

	/** A review's commit count: every product any of its parts writes to, once. */
	const commitIds = ( r ) => [ ...new Set( r.plans.flatMap( ( p ) => p.ids ) ) ];

	function drawReview() {
		const r = state.reviews[ state.shown ];
		if ( ! r ) {
			return;
		}
		const keep = sheet.querySelector( '.shb' );
		const scroll = keep ? keep.scrollTop : 0;
		const focused = sheet.contains( root.activeElement ); // Escape needs the focus in here after a redraw
		const c = r.c;
		const hd = el( 'div', 'shh' );
		const t = el( 'div', 't' );
		t.append( el( 'h2', '', r.scope === 'links' ? MESSAGES.sheetLinks : r.scope === 'site' ? MESSAGES.sheetSite.replace( '%n', c.site.products ) : r.scope === 'layout' ? MESSAGES.sheetLayout.replace( '%l', c.layout.name ).replace( '%n', c.layout.products ) : MESSAGES.sheetProduct ), el( 'div', 's', r.scope === 'links' ? MESSAGES.linksSub : MESSAGES.sheetSub ) );
		const x = el( 'button', 'ib', '×' );
		x.type = 'button';
		x.title = MESSAGES.hide;
		x.setAttribute( 'aria-label', MESSAGES.hide );
		x.addEventListener( 'click', hideReview );
		hd.append( t, x );
		const bd = el( 'div', 'shb' );
		if ( r.error ) {
			bd.append( notice( 'error', r.error ) );
		}
		if ( r.busy ) {
			const n = spinning( r.busy.trim() || MESSAGES.working );
			if ( wide( r ) ) {
				n.append( el( 'div', '', MESSAGES.lookingSlow ) );
			}
			bd.append( n );
		}
		if ( r.scope === 'links' ) {
			if ( r.data ) {
				const d = r.data;
				bd.append( el( 'p', 'gl', MESSAGES.linksCounts.replace( '%l', d.listed ).replace( '%t', d.total ).replace( '%c', d.confirmed ) + ( d.waiting ? MESSAGES.linksWaiting.replace( '%w', d.waiting ) : '' ) ) );
				if ( ! d.rows.length ) {
					bd.append( notice( 'ok', MESSAGES.linksNone ) );
				}
				d.rows.forEach( ( x ) => bd.append( linkRow( r, x ) ) );
				if ( ( d.proposals || [] ).length ) {
					const head = el( 'h3', 'ph3', MESSAGES.proposalsHead.replace( '%n', d.proposals.length ) );
					bd.append( head );
					d.proposals.forEach( ( x ) => bd.append( proposalRow( x ) ) );
				}
			}
		} else if ( wide( r ) ) {
			r.plans.forEach( ( p ) => bd.append( planCard( r, p ) ) );
			if ( ! r.busy && r.left && r.left.length ) {
				bd.append( notice( 'warn', ( r.plans.length ? MESSAGES.notIncluded.replace( '%p', r.left.join( ', ' ) ) : MESSAGES.noneToCommit ).replace( /%l/g, whose( r ) ) ) );
			}
		} else if ( r.data ) {
			r.data.parts.forEach( ( p ) => {
				const card = el( 'section', 'pc' );
				const pane = el( 'div', 'ex' );
				pane.append( exampleBody( p, r.data.product ) );
				card.append( el( 'h3', '', p.label ), pane );
				bd.append( card );
			} );
		}
		sheet.replaceChildren( hd, bd, reviewFoot( r ) );
		bd.scrollTop = scroll;
		if ( focused ) {
			sheet.focus();
		}
	}

	/** One part's outcomes on the layout's products. */
	function planCard( r, p ) {
		const card = el( 'section', 'pc' );
		const h = el( 'h3', '', p.label );
		h.append( el( 'span', '', MESSAGES.outOf.replace( '%n', p.ids.length ).replace( '%t', p.total ) ) );
		card.append( h );
		if ( ! p.live ) {
			card.append( el( 'p', 'gl', MESSAGES.goesLive.replace( '%l', whose( r ) ) ) );
		}
		p.groups.forEach( ( g ) => card.append( groupOf( r, p, g ) ) );
		return card;
	}

	/** An outcome: how many, what it means, what to do, and its products with an example of any of them. */
	function groupOf( r, p, g ) {
		const key = p.part + ':' + g.key;
		const box = el( 'div', 'gr ' + g.tone );
		const hd = el( 'div', 'gh' );
		hd.append( el( 'span', 'chip ' + g.tone, { change: MESSAGES.toneChange, look: MESSAGES.toneLook, same: MESSAGES.toneSame }[ g.tone ] ), el( 'b', '', g.n + ' · ' + g.title ) );
		box.append( hd, el( 'p', '', g.text ) );
		if ( g.fix ) {
			box.append( el( 'p', 'fix', g.fix ) );
		}
		const links = el( 'div', 'ga' );
		const first = g.products[ 0 ];
		if ( first ) {
			const one = el( 'button', 'lk', MESSAGES.exampleOne );
			one.type = 'button';
			one.addEventListener( 'click', () => {
				r.open[ key ] = r.open[ key ] || 'one';
				r.open[ key + ':' + first.id ] = true;
				drawReview();
			} );
			links.append( one );
		}
		const listed = r.open[ key ] === 'all';
		const list = el( 'button', 'lk', listed ? MESSAGES.hideProducts : MESSAGES.showProducts.replace( '%n', g.n ) );
		list.type = 'button';
		list.setAttribute( 'aria-expanded', String( listed ) );
		list.addEventListener( 'click', () => {
			r.open[ key ] = listed ? false : 'all';
			drawReview();
		} );
		links.append( list );
		box.append( links );
		if ( r.open[ key ] ) {
			const max = listed ? ( r.all[ key ] ? g.products.length : 30 ) : 1;
			const ul = el( 'ul', 'pl' );
			g.products.slice( 0, max ).forEach( ( x ) => ul.append( productOf( r, p, key, x, g.tone ) ) );
			box.append( ul );
			if ( listed && g.products.length > max ) {
				const all = el( 'button', 'lk', MESSAGES.showAll.replace( '%n', g.products.length ) );
				all.type = 'button';
				all.addEventListener( 'click', () => {
					r.all[ key ] = true;
					drawReview();
				} );
				box.append( all );
			}
		}
		return box;
	}

	/** A product under an outcome, with its example opened in place. */
	function productOf( r, p, key, x, tone ) {
		const open = !! r.open[ key + ':' + x.id ];
		const li = el( 'li' );
		const row = el( 'div', 'pt' );
		const b = el( 'button', 'lk', open ? MESSAGES.hideIt : MESSAGES.seeIt );
		b.type = 'button';
		b.setAttribute( 'aria-expanded', String( open ) );
		b.addEventListener( 'click', () => {
			r.open[ key + ':' + x.id ] = ! open;
			drawReview();
		} );
		row.append( el( 'span', '', x.title ), b );
		li.append( row );
		if ( open ) {
			li.append( examplePane( r, p.part, x, tone ) );
		}
		return li;
	}

	/** A product's example, asked for once. An outcome that writes nothing shows the product staying as it is, whatever a general read of its page would give. */
	function examplePane( r, part, x, tone ) {
		const k = part + ':' + x.id;
		const box = el( 'div', 'ex' );
		const got = r.examples[ k ];
		if ( got && got.error ) {
			box.append( notice( 'error', got.error ) );
			if ( sameSite( x.url ) ) {
				box.append( link( 'a', '', MESSAGES.openPage + ' ↗', x.url ) );
			}
		} else if ( got ) {
			box.append( exampleBody( got.parts[ 0 ], got.product ) );
		} else {
			box.append( spinning( MESSAGES.working ) );
			r.examples[ k ] = null;
			ask( { type: 'example', url: location.href, product: x.id, parts: [ part ], scope: 'layout', stays: tone !== 'change' } ).then( ( res ) => {
				r.examples[ k ] = res && res.ok ? res.data : { error: ( res && res.data && res.data.message ) || MESSAGES.failed };
				redraw( r );
			} );
		}
		return box;
	}

	/** Now beside after for one part, with where to see it for real. */
	function exampleBody( p, product ) {
		const box = el( 'div' );
		box.append( el( 'div', 'say', p.will === 'write' ? MESSAGES.willWrite : p.will === 'review' ? MESSAGES.willReview : MESSAGES.willNot ) );
		if ( product.match && ! product.match.ok && product.match.kind !== 'ok' ) { // the example may be wrong for a simpler reason: the wrong page
			const m = el( 'div', 'mt' );
			m.append( el( 'span', 'chip ' + product.match.kind, product.match.word ), document.createTextNode( ' ' + MESSAGES.pageIs.replace( '%t', product.page_title || '' ) + ' ' + product.match.help ) );
			box.append( m );
		}
		const links = el( 'div', 'xl' );
		const preview = ownLink( p.preview || '' );
		if ( preview ) {
			links.append( link( 'a', '', MESSAGES.previewOn + ' ↗', preview ) );
		}
		if ( sameSite( product.page ) ) {
			links.append( link( 'a', '', MESSAGES.openPage + ' ↗', product.page ) );
		}
		const view = ownLink( product.view || '' );
		if ( view ) {
			links.append( link( 'a', '', MESSAGES.openOn + ' ↗', view ) );
		}
		box.append( links );
		const same = ! p.will;
		const cols = el( 'div', same ? '' : 'cols' );
		if ( same ) {
			cols.append( columnOf( MESSAGES.stays, p, p.now ) );
		} else {
			const counted = ( chars, count, n ) => ( p.kind === 'text' ? chars : count ).replace( '%n', Number( n ).toLocaleString() );
			const nowN = p.kind === 'text' ? p.now_chars : p.kind === 'pictures' ? p.now_n : p.now.length;
			const afterN = p.kind === 'text' ? p.after_chars : p.kind === 'pictures' ? p.after_n : p.after.length;
			cols.append( columnOf( counted( MESSAGES.nowChars, MESSAGES.nowCount, nowN ), p, p.now ), columnOf( counted( MESSAGES.afterChars, MESSAGES.afterCount, afterN ), p, p.after ) );
		}
		box.append( cols );
		if ( ! same && p.kind === 'text' ) {
			box.append( el( 'div', 'ky', MESSAGES.changedKey ) );
		}
		return box;
	}

	function columnOf( title, p, v ) {
		const col = el( 'div', 'col' );
		col.append( el( 'h4', '', title ) );
		if ( ! v.length ) {
			col.append( el( 'div', 'tx', MESSAGES.none ) );
		} else if ( p.kind === 'text' ) {
			const tx = el( 'div', 'tx' );
			v.forEach( ( [ words, changed ] ) => tx.append( changed && p.will ? el( 'mark', '', words ) : document.createTextNode( words ), document.createTextNode( ' ' ) ) );
			col.append( tx );
		} else if ( p.kind === 'pictures' ) {
			const ph = el( 'div', 'ph' );
			v.filter( ( x ) => /^https?:\/\//i.test( x.url ) ).forEach( ( x ) => {
				const f = el( 'figure' );
				const img = el( 'img' );
				img.alt = '';
				img.referrerPolicy = 'no-referrer';
				img.loading = 'lazy';
				img.src = x.url;
				f.append( img );
				if ( x.tag ) {
					f.append( el( 'figcaption', '', x.tag ) );
				}
				ph.append( f );
			} );
			col.append( ph );
		} else {
			const ul = el( 'ul' );
			v.forEach( ( line ) => ul.append( el( 'li', '', line ) ) );
			col.append( ul );
		}
		return col;
	}

	/** Commit, and the way out; the layout's review also says how old its look is. */
	function reviewFoot( r ) {
		const ft = el( 'div', 'shf' );
		if ( r.scope === 'links' ) {
			const shut = el( 'button', 'b', MESSAGES.close );
			shut.type = 'button';
			shut.addEventListener( 'click', hideReview );
			ft.append( shut );
			return ft;
		}
		const ids = wide( r ) ? commitIds( r ) : [];
		const writes = r.scope === 'product' ? r.parts.filter( ( k ) => ( r.c.writes || [] ).includes( k ) ) : [];
		const can = ! r.busy && ( wide( r ) ? ids.length > 0 : writes.length > 0 );
		const label = r.busy ? MESSAGES.working : ! can ? MESSAGES.commitNoChange : wide( r ) ? counted( MESSAGES.commitLayout, ids.length ) : MESSAGES.commitProduct; // no promise of a commit until it's known
		const go = el( 'button', 'b pr', label );
		go.type = 'button';
		go.disabled = ! can;
		go.addEventListener( 'click', () => ( wide( r ) ? commitLayout( r, go ) : commitProduct( r, go ) ) );
		const no = el( 'button', 'b', MESSAGES.close );
		no.type = 'button';
		no.addEventListener( 'click', hideReview );
		const note = el( 'div', 's', can ? ( wide( r ) ? MESSAGES.commitNote : MESSAGES.commitNoteProduct ) : '' );
		const at = r.plans.length ? Math.min( ...r.plans.map( ( p ) => p.at || 0 ) ) : 0;
		if ( wide( r ) && ! r.busy && at ) {
			const mins = Math.max( 0, Math.round( ( Date.now() / 1000 - at ) / 60 ) );
			if ( mins > 0 ) {
				note.append( ' ' + counted( MESSAGES.looked, mins ) + ' ' );
				const again = el( 'button', 'lk', MESSAGES.lookAgain );
				again.type = 'button';
				again.addEventListener( 'click', () => {
					r.busy = ' ';
					lookFor( r, true );
				} );
				note.append( again );
			}
		}
		ft.append( go, no, note );
		return ft;
	}

	/** The ticked parts to this product: the page as drawn is stored, and only those parts are written. */
	async function commitProduct( r, b ) {
		const parts = r.parts.filter( ( k ) => ( r.c.writes || [] ).includes( k ) );
		const html = state.base ? '' : pageCopy(); // from our own product page: the stored copy, never this page's HTML
		if ( html || state.base ) {
			const res = await working( b, MESSAGES.writing, () => ask( { type: 'write', url: at(), product: r.c.product, expect: parts, only: r.parts, html } ) );
			forgetReviews();
			answered( res, MESSAGES.writeStarted );
		}
	}

	/** Each part that writes something to the layout's products, as one batch: one Undo puts them all back. */
	async function commitLayout( r, b ) {
		const go = r.plans.filter( ( p ) => p.ids.length );
		const batch = Date.now().toString( 36 );
		const said = [];
		let res = null;
		await working( b, MESSAGES.committing.replace( '%p', go.map( ( p ) => p.label ).join( ', ' ) ), async () => {
			for ( const p of go ) {
				res = await ask( { type: askType( r ), url: location.href, op: 'write', part: p.part, batch, product: state.product } );
				if ( ! res || ! res.ok ) {
					return;
				}
				said.push( ( res.data && res.data.message ) || '' );
			}
		} );
		if ( res && res.ok ) {
			res.data.message = said.join( ' ' );
		}
		forgetReviews();
		answered( res );
	}

	/* ── Drawing the server's description ──────────────────────────────── */

	const ask = ( msg ) => chrome.runtime.sendMessage( msg ).catch( () => ( { ok: false, data: { message: MESSAGES.reloaded } } ) );

	function notice( kind, text, button ) {
		const n = el( 'div', 'n ' + ( kind || '' ), text );
		if ( button ) {
			const b = el( 'button', 'b', button.label );
			b.addEventListener( 'click', button.run );
			n.append( el( 'br' ), b );
		}
		return n;
	}

	function spinning( text ) {
		const n = el( 'div', 'n' );
		n.append( el( 'span', 'spin' ), document.createTextNode( text ) );
		return n;
	}

	function busy( text ) {
		body.replaceChildren( spinning( text ) );
	}

	/** Only the store's own pages open from the panel. */
	function ownLink( href ) {
		try {
			return STORE.origin && new URL( href ).origin === STORE.origin ? href : '';
		} catch ( e ) {
			return '';
		}
	}

	function link( tag, cls, text, href ) {
		const a = el( tag, cls, text );
		a.href = href;
		a.target = '_blank';
		a.rel = 'noopener';
		return a;
	}

	function button( a ) {
		const b = el( 'button', 'b' + ( a.primary ? ' pr' : '' ), a.label || '' );
		b.addEventListener( 'click', () => act( a, b ) );
		return b;
	}

	/** What a row's criteria read, outlined until the pointer leaves it. A site's tab step (":tab()") isn't a selector here. */
	function peekDraw() {
		while ( layer.children.length < peeking.length ) {
			layer.append( el( 'i' ) );
		}
		Array.from( layer.children ).forEach( ( b, n ) => {
			const r = peeking[ n ] && peeking[ n ].isConnected ? peeking[ n ].getBoundingClientRect() : null;
			b.hidden = ! r || ! r.width || ! r.height;
			if ( ! b.hidden ) {
				b.style.cssText = `left:${ r.left }px;top:${ r.top }px;width:${ r.width }px;height:${ r.height }px`;
			}
		} );
		peekFrame = peeking.length ? window.requestAnimationFrame( peekDraw ) : 0;
	}
	/** The smallest box on the page holding both ends of a text, spaces aside; failing that, the one holding its start. */
	function textBox( [ start, end ] ) {
		const bare = ( t ) => t.replace( /\s+/g, '' );
		const [ a, b ] = [ bare( start ), bare( end ) ];
		const boxes = Array.from( document.querySelectorAll( 'body div, body section, body article, body main, body td' ) ).map( ( e ) => [ e, bare( e.textContent ) ] ).filter( ( [ , t ] ) => t.includes( a ) );
		const both = boxes.filter( ( [ , t ] ) => t.includes( b ) );
		const best = ( both.length ? both : boxes ).sort( ( x, y ) => x[ 1 ].length - y[ 1 ].length )[ 0 ];
		return best ? [ best[ 0 ] ] : [];
	}
	/** Each item's own element on the page, the smallest showing its opening words: the items can sit in separate lists. */
	function itemBoxes( items ) {
		const bare = ( t ) => t.replace( /\s+/g, '' );
		const boxes = Array.from( document.querySelectorAll( 'body li, body dd, body td, body p' ) ).filter( ( e ) => e.getClientRects().length ).map( ( e ) => [ e, bare( e.textContent ) ] );
		return items.flatMap( ( item ) => {
			const words = bare( item ).slice( 0, PEEK.words );
			const best = boxes.filter( ( [ , t ] ) => t.includes( words ) ).sort( ( x, y ) => x[ 1 ].length - y[ 1 ].length )[ 0 ];
			return best ? [ best[ 0 ] ] : [];
		} );
	}
	function peekOn( r ) {
		peekOff();
		try {
			const all = r.sel ? Array.from( document.querySelectorAll( r.sel.replace( /:tab\([^)]*\)/g, '' ) ) ) : r.items ? itemBoxes( r.items ) : textBox( r.find );
			peeking = all.filter( ( e ) => ! all.some( ( o ) => o !== e && o.contains( e ) ) ).slice( 0, PEEK.max );
		} catch ( e ) {
			peeking = [];
		}
		peekDraw();
		const inView = ( e ) => {
			const r = e.getBoundingClientRect();
			return r.bottom > 0 && r.top < window.innerHeight;
		};
		if ( peeking.length && ! peeking.some( inView ) ) {
			peekWait = window.setTimeout( () => peeking[ 0 ] && peeking[ 0 ].scrollIntoView( { block: 'center' } ), PEEK.waitMs ); // a pointer passing over rows doesn't move the page
		}
	}
	function peekOff() {
		window.clearTimeout( peekWait );
		window.cancelAnimationFrame( peekFrame );
		peeking = [];
		Array.from( layer.children ).forEach( ( b ) => {
			b.hidden = true;
		} );
	}

	function rowOf( r ) {
		const row = el( 'div', 'row' );
		if ( r.sel || r.find || ( r.items && r.items.length ) ) {
			row.classList.add( 'shows' );
			[ 'mouseenter', 'focusin' ].forEach( ( t ) => row.addEventListener( t, () => peekOn( r ) ) );
			[ 'mouseleave', 'focusout' ].forEach( ( t ) => row.addEventListener( t, peekOff ) );
		}
		const txt = el( 'div' );
		const href = r.href ? ownLink( r.href ) : '';
		let label;
		let go = null;
		if ( r.action && r.hint ) { // protocol 6: the whole row opens picking for its part
			go = el( 'span', 'go', r.hint + ' ›' );
			row.classList.add( 'pick' );
			row.tabIndex = 0;
			row.setAttribute( 'role', 'button' );
			row.title = r.hint;
			const run = ( e ) => {
				if ( e.target.closest( 'button, a, input, textarea' ) ) {
					return; // a thumbnail's ×, a tick: their own click
				}
				if ( e.type === 'keydown' ) {
					if ( e.key !== 'Enter' && e.key !== ' ' ) {
						return;
					}
					e.preventDefault();
				}
				act( r.action, go );
			};
			row.addEventListener( 'click', run );
			row.addEventListener( 'keydown', run );
			label = el( 'div', 'l', r.label || '' );
		} else if ( r.action ) {
			label = el( 'button', 'l', r.label || '' );
			label.type = 'button';
			label.addEventListener( 'click', () => act( r.action, label ) );
		} else {
			label = href ? link( 'a', 'l', r.label || '', href ) : el( 'div', 'l', r.label || '' );
		}
		const line = el( 'div', 'lh' );
		line.append( label );
		const chip = r.status ? el( 'span', 'chip' ) : null;
		const why = r.status ? el( 'div', 'why' ) : null;
		/** A part's state in words; a part that would change but is unticked is left out, and says so. */
		const stateIs = ( on ) => {
			const out = r.status.kind === 'change' && ! on;
			chip.className = 'chip ' + ( out ? '' : r.status.kind );
			chip.textContent = out ? MESSAGES.leftOut : r.status.word;
			chip.title = out ? MESSAGES.leftOutHelp : r.status.help;
			why.textContent = r.status.kind === 'missing' || r.status.kind === 'review' ? r.status.help : '';
			why.hidden = ! why.textContent;
		};
		if ( chip ) {
			line.append( chip );
		}
		txt.append( line );
		if ( r.value ) {
			txt.append( el( 'div', 'v', r.value ) );
		}
		if ( why ) {
			txt.append( why );
		}
		if ( r.criteria ) {
			txt.append( el( 'div', 'cr', r.criteria ) );
		}
		if ( Array.isArray( r.thumbs ) && r.thumbs.length ) {
			const th = el( 'div', 'th' );
			r.thumbs.forEach( ( src ) => {
				if ( /^https?:\/\//i.test( src ) ) {
					const wrap = el( 'span' );
					const img = el( 'img' );
					img.alt = '';
					img.referrerPolicy = 'no-referrer';
					img.loading = 'lazy';
					img.src = src;
					img.title = src;
					wrap.append( img );
					if ( r.thumb_action ) {
						const x = el( 'button', '', '×' );
						x.type = 'button';
						x.title = r.thumb_action.label || '';
						x.setAttribute( 'aria-label', r.thumb_action.label || '' );
						x.addEventListener( 'click', () => act( Object.assign( {}, r.thumb_action, { picture: src, product: state.product } ), x ) );
						wrap.append( x );
					}
					th.append( wrap );
				}
			} );
			txt.append( th );
		}
		if ( r.commit && r.commit.key ) { // protocol 7: the part goes into the next commit when ticked
			const ck = el( 'input', 'ck' );
			ck.type = 'checkbox';
			ck.title = MESSAGES.tickTitle;
			ck.setAttribute( 'aria-label', MESSAGES.tickTitle + ': ' + ( r.label || '' ) );
			ck.checked = ticked( r.commit.key, !! r.commit.checked );
			ck.addEventListener( 'click', ( e ) => e.stopPropagation() ); // the row's own click opens picking
			ck.addEventListener( 'change', () => {
				state.ticks[ r.commit.key ] = ck.checked;
				if ( chip ) {
					stateIs( ck.checked );
				}
				if ( state.say ) {
					state.say();
				}
			} );
			row.append( ck );
			if ( chip ) {
				stateIs( ck.checked );
			}
		} else if ( chip ) {
			stateIs( true );
		}
		if ( go ) {
			txt.append( go );
		}
		if ( r.away ) {
			const away = el( 'button', 'lk', r.away.label );
			away.type = 'button';
			away.addEventListener( 'click', () => act( { kind: 'hand', op: 'visit', url: r.away.url, product: 0, title: '' }, away ) );
			txt.append( away );
		}
		if ( r.edit ) {
			txt.append( typedField( r ) );
		}
		if ( r.brand ) {
			txt.append( brandPick( r.brand ) );
		}
		if ( r.site ) {
			txt.append( siteField( r.site ) );
		}
		if ( r.match ) {
			txt.append( matchActions( r.match, true ) );
		}
		if ( r.proposal ) {
			txt.append( proposalActions( r.proposal, true ) );
		}
		row.append( txt );
		return row;
	}

	/** On one of our product pages: its brand, chosen by name. Open at once when it has none. */
	function brandPick( b ) {
		const box = el( 'div', 'ma' );
		const form = el( 'div', 'edf' );
		form.hidden = ! b.open;
		const input = el( 'input' );
		input.type = 'search';
		input.placeholder = MESSAGES.brandFind;
		input.setAttribute( 'aria-label', MESSAGES.brandFind );
		const list = el( 'div' );
		const paste = el( 'div', 'pa' );
		paste.append( input );
		form.append( el( 'h4', '', MESSAGES.brandHead ), paste, list );
		if ( ! b.open ) {
			const open = el( 'button', 'lk', MESSAGES.changeBrand );
			open.type = 'button';
			open.addEventListener( 'click', () => {
				form.hidden = ! form.hidden;
				input.focus();
			} );
			box.append( open );
		}
		const pick = async ( x, use ) => answered( await working( use, MESSAGES.saving, () => ask( { type: 'mapping', url: location.href, op: 'brand', product: b.product, term: x.term, panel: 1 } ) ) );
		let timer = 0;
		let seq = 0;
		input.addEventListener( 'input', () => {
			window.clearTimeout( timer );
			timer = window.setTimeout( async () => {
				const q = input.value.trim();
				const mine = ++seq;
				if ( q.length < 2 ) {
					list.replaceChildren();
					return;
				}
				list.replaceChildren( spinning( MESSAGES.searching ) );
				const res = await ask( { type: 'mapping', url: location.href, op: 'brands', product: b.product, q } );
				if ( mine !== seq ) {
					return; // a newer search is on its way
				}
				const rows = ( res && res.ok && res.data.rows ) || [];
				list.replaceChildren( ...( rows.length ? rows.map( ( x ) => {
					const row = el( 'div', 'lp' );
					const use = el( 'button', 'b', MESSAGES.use );
					use.type = 'button';
					use.addEventListener( 'click', () => pick( x, use ) );
					row.append( el( 'div', 'lw', x.name + ( x.site ? ' · ' + x.site : '' ) ), use );
					return row;
				} ) : [ el( 'div', 'pl2', MESSAGES.brandNone ) ] ) );
			}, 400 );
		} );
		box.append( form );
		return box;
	}

	/** On one of our product pages: the site of a brand that has none, or the word that it has none. */
	function siteField( x ) {
		const box = el( 'div', 'ma' );
		const form = el( 'div', 'edf' );
		const tell = async ( op, b, target ) => answered( await working( b, MESSAGES.saving, () => ask( { type: 'mapping', url: location.href, op, product: x.product, term: x.term, target, panel: 1 } ) ) );
		if ( x.add ) {
			const add = el( 'button', 'b', MESSAGES.addSite.replace( '%h', x.add ).replace( '%b', x.name ) );
			add.type = 'button';
			add.addEventListener( 'click', () => tell( 'site', add, x.add ) );
			box.append( add );
			return box;
		}
		const paste = el( 'div', 'pa' );
		const field = el( 'input' );
		field.type = 'url';
		field.placeholder = 'https://';
		field.setAttribute( 'aria-label', MESSAGES.sitePaste );
		const go = el( 'button', 'b', MESSAGES.use );
		go.type = 'button';
		const save = () => field.value.trim() && tell( 'site', go, field.value.trim() );
		go.addEventListener( 'click', save );
		field.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				save();
			}
		} );
		paste.append( field, go );
		form.append( el( 'h4', '', MESSAGES.siteHead ), el( 'div', 'pl2', MESSAGES.sitePaste ), paste );
		if ( ! x.gone ) {
			const none = el( 'button', 'b', MESSAGES.noSite );
			none.type = 'button';
			let armed = false;
			none.addEventListener( 'click', () => {
				if ( ! armed ) {
					armed = true;
					none.textContent = MESSAGES.noSiteGo;
					return;
				}
				tell( 'nosite', none, '' );
			} );
			form.append( none );
		}
		box.append( form );
		return box;
	}

	/**
	 * What a person can decide about a product's vendor page: it's right, or it isn't: then the maker doesn't publish it
	 * (a second click, with Put it back) or the right page is set in Product Data. $inPanel: the answer redraws the panel.
	 */
	function matchActions( m, inPanel, done ) {
		const box = el( 'div', 'ma' );
		const tell = async ( op, b, extra ) => {
			const res = await working( b, MESSAGES.saving, () => ask( Object.assign( { type: 'mapping', url: location.href, op, product: m.product, panel: inPanel ? 1 : 0 }, extra ) ) );
			if ( inPanel || ! res || ! res.ok ) {
				answered( res );
			}
			return res;
		};
		const lk = ( text, run ) => {
			const b = el( 'button', 'lk', text );
			b.type = 'button';
			b.addEventListener( 'click', () => run( b ) );
			return b;
		};
		if ( m.ok ) {
			box.append( lk( MESSAGES.unsure, ( b ) => tell( 'unsure', b ) ) );
			return box;
		}
		const more = el( 'div', 'edf' );
		more.hidden = ! m.empty && ! ( m.likely || [] ).some( ( p ) => p.proposed ); // a page proposed for it shows at once
		/** The right page set: inside the sheet the row says so, with Undo. */
		const setTo = async ( target, b ) => {
			const res = await tell( 'set', b, { target } );
			if ( res && res.ok && ! inPanel ) {
				const line = el( 'div', 'v', res.data.message || '' );
				const x = res.data.linked;
				if ( x && ! x.here ) {
					line.append( ' ', lk( MESSAGES.linkedOpen, ( o ) => openLinked( x, o ) ) );
				}
				line.append( ' ', lk( MESSAGES.linkedUndo, ( u ) => tell( 'undo', u ).then( ( back ) => {
					if ( back && back.ok ) {
						box.replaceChildren( el( 'div', 'v', back.data.message || '' ) );
					}
				} ) ) );
				box.replaceChildren( line );
				if ( done ) {
					done( 'set' );
				}
			}
		};
		more.append( el( 'h4', '', MESSAGES.rightHead ) );
		( m.likely || [] ).forEach( ( p, i ) => {
			const row = el( 'div', 'lp' );
			const words = el( 'div', 'lw' );
			words.append( el( 'span', '', ( p.proposed ? MESSAGES.proposed + ' ' : i ? '' : MESSAGES.likely + ' ' ) + p.short ), ' ', m.store ? lk( MESSAGES.openIt, () => act( { kind: 'hand', op: 'start', url: p.url, product: m.product, title: m.title }, el( 'button' ) ) ) : sameSite( p.url ) ? link( 'a', '', MESSAGES.openOnly + ' ↗', p.url ) : '' );
			const use = el( 'button', 'b', MESSAGES.usePage );
			use.type = 'button';
			use.addEventListener( 'click', () => setTo( p.url, use ) );
			row.append( words, use );
			more.append( row );
		} );
		const paste = el( 'div', 'pa' );
		const field = el( 'input' );
		field.type = 'url';
		field.placeholder = 'https://';
		field.setAttribute( 'aria-label', MESSAGES.paste );
		const go = el( 'button', 'b', MESSAGES.use );
		go.type = 'button';
		const pasted = () => field.value.trim() && setTo( field.value.trim(), go );
		go.addEventListener( 'click', pasted );
		field.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				pasted();
			}
		} );
		paste.append( field, go );
		more.append( el( 'div', 'pl2', m.store && ! ( m.likely || [] ).length ? MESSAGES.pasteFirst : MESSAGES.paste ), paste );
		const seek = lk( MESSAGES.findIt, () => ask( { type: 'hand', op: 'start', product: m.product, title: m.title || '' } ) );
		seek.title = MESSAGES.findHelp;
		if ( ! m.store ) { // on our own page the footer has the sites to find it on
			more.append( seek );
		}
		if ( m.marked ) {
			box.append( more );
			return box;
		}
		more.append( el( 'div', 'pl2', MESSAGES.noPage ) );
		const none = el( 'button', 'b', MESSAGES.noPublish );
		none.type = 'button';
		let armed = false;
		none.addEventListener( 'click', async () => {
			if ( ! armed ) {
				armed = true;
				none.textContent = MESSAGES.noPublishGo;
				return;
			}
			const res = await tell( 'none', none );
			if ( res && res.ok && ! inPanel ) {
				const was = res.data.was || '';
				const line = el( 'div', 'v', res.data.message || '' );
				line.append( ' ', lk( MESSAGES.putBack, ( b ) => tell( 'restore', b, { was } ).then( () => box.replaceChildren( el( 'div', 'v', '' ) ) ) ) );
				box.replaceChildren( line );
				if ( done ) {
					done( 'none' );
				}
			}
		} );
		more.append( none, el( 'div', 'v', MESSAGES.noPublishHelp ) );
		const line = el( 'div', 'mb' );
		line.append(
			lk( MESSAGES.itsRight, async ( b ) => {
				const res = await tell( 'right', b );
				if ( res && res.ok && ! inPanel && done ) {
					done( 'right' );
				}
			} ),
			lk( MESSAGES.notIts, () => {
				more.hidden = ! more.hidden;
			} )
		);
		if ( m.empty ) {
			box.append( more );
			return box;
		}
		box.append( line, more );
		return box;
	}

	/** A part typed by a person, for what the page doesn't say: under its row, opened on request. */
	function typedField( r ) {
		const box = el( 'div', 'ed' );
		const open = el( 'button', 'lk', MESSAGES.typeIt );
		open.type = 'button';
		const area = el( 'textarea' );
		area.rows = 3;
		area.maxLength = 1000;
		area.value = r.edit.value || '';
		area.setAttribute( 'aria-label', r.label || '' );
		const save = el( 'button', 'b', MESSAGES.save );
		save.type = 'button';
		const cancel = el( 'button', 'b', MESSAGES.cancel );
		cancel.type = 'button';
		const form = el( 'div', 'edf' );
		const acts = el( 'div', 'row2' );
		acts.append( save, cancel );
		form.append( area, acts );
		form.hidden = true;
		open.addEventListener( 'click', () => {
			form.hidden = false;
			open.hidden = true;
			area.focus();
		} );
		cancel.addEventListener( 'click', () => {
			form.hidden = true;
			open.hidden = false;
		} );
		save.addEventListener( 'click', async () => {
			answered( await working( save, MESSAGES.saving, () => ask( { type: 'disclaimer', url: at(), product: r.edit.product, text: area.value } ) ) );
		} );
		box.append( open, form );
		return box;
	}

	/** What the part labels mean and what to do about each, shown on request. */
	function keyOf( key ) {
		const box = el( 'div', 'key' );
		const b = el( 'button', 'lk', MESSAGES.keyToggle );
		b.type = 'button';
		b.setAttribute( 'aria-expanded', String( state.key ) );
		const dl = el( 'dl' );
		[ ...key, { kind: '', word: MESSAGES.leftOut, help: MESSAGES.leftOutHelp } ].forEach( ( k ) => {
			const dt = el( 'dt' );
			dt.append( el( 'span', 'chip ' + k.kind, k.word ) );
			dl.append( dt, el( 'dd', '', k.help ) );
		} );
		dl.hidden = ! state.key;
		b.addEventListener( 'click', () => {
			state.key = ! state.key;
			dl.hidden = ! state.key;
			b.setAttribute( 'aria-expanded', String( state.key ) );
		} );
		box.append( b, dl );
		return box;
	}

	/** What can be put back, each in its own words. */
	function undoList( items ) {
		const box = el( 'div', 'un' );
		box.append( el( 'h3', '', MESSAGES.undoHead ) );
		items.forEach( ( u ) => {
			const line = el( 'div' );
			const b = el( 'button', 'lk', u.action.label || '' );
			b.type = 'button';
			b.addEventListener( 'click', () => act( u.action, b ) );
			line.append( el( 'span', '', u.text ), b );
			box.append( line );
		} );
		return box;
	}

	/** The one place that acts: the commit, what must stay in sight, and the rest under More with what each does. */
	function drawFoot( panel ) {
		const commit = ( panel.sections || [] ).map( ( s ) => s.commit ).find( Boolean );
		const all = [ ...( panel.sections || [] ).flatMap( ( s ) => s.more || [] ), ...( panel.actions || [] ) ];
		if ( ! state.allowed ) {
			all.push( { kind: 'allow', label: MESSAGES.keepOpen } );
		}
		const main = all.filter( ( a ) => a.primary );
		const rest = all.filter( ( a ) => ! a.primary );
		state.say = null;
		if ( commit ) {
			foot.append( commitBlock( commit ) );
		}
		const line = el( 'div', 'row2' );
		main.forEach( ( a ) => line.append( button( a ) ) );
		if ( rest.length ) {
			const list = el( 'div', 'ml' );
			rest.forEach( ( a ) => {
				const b = el( 'button' );
				b.type = 'button';
				b.append( el( 'b', '', a.label || '' ) );
				if ( a.help ) {
					b.append( el( 'span', '', a.help ) );
				}
				b.addEventListener( 'click', () => act( a, b ) );
				list.append( b );
			} );
			list.hidden = ! state.more;
			const more = el( 'button', 'b', MESSAGES.more + ( state.more ? ' ▾' : ' ▴' ) );
			more.type = 'button';
			more.setAttribute( 'aria-expanded', String( state.more ) );
			more.addEventListener( 'click', () => {
				state.more = ! state.more;
				list.hidden = ! state.more;
				more.textContent = MESSAGES.more + ( state.more ? ' ▾' : ' ▴' );
				more.setAttribute( 'aria-expanded', String( state.more ) );
			} );
			foot.prepend( list );
			line.append( more );
		}
		if ( line.children.length ) {
			foot.append( line );
		}
	}

	function draw( panel, extra ) {
		peekOff(); // its row is about to go, and with it the pointer's leaving
		const scroll = body.scrollTop;
		const top = panel.href ? ownLink( panel.href ) : ''; // the store's Workbench on this product or site
		h1.replaceChildren( top ? link( 'a', '', panel.title || 'Product Data', top ) : document.createTextNode( panel.title || 'Product Data' ) );
		sub.textContent = panel.subtitle || sub.textContent;
		state.product = panel.product || state.product;
		if ( state.ticksFor !== state.product ) { // another product: its own ticks
			state.ticks = {};
			state.ticksFor = state.product;
			forgetReviews();
		}
		state.base = panel.base || '';
		state.look = state.look || !! panel.base; // the server opened it on a job: it stays open as the job goes on
		body.replaceChildren();
		foot.replaceChildren();
		if ( extra ) {
			body.append( extra );
		}
		if ( state.hand ) {
			body.append( handBar() );
		}
		( panel.notices || [] ).forEach( ( n ) => body.append( notice( n.kind, n.text ) ) );
		( panel.sections || [] ).forEach( ( s ) => {
			const sec = el( 'div', 'sec' + ( s.actions ? ' product' : '' ) );
			const h2 = el( 'h2' );
			const href = s.href ? ownLink( s.href ) : '';
			h2.append( href ? link( 'a', '', s.title || '', href ) : document.createTextNode( s.title || '' ) );
			sec.append( h2 );
			const links = ( s.links || [] ).map( ( l ) => [ l.label || '', ownLink( l.href || '' ) ] ).filter( ( [ , href ] ) => href );
			if ( links.length ) {
				const lks = el( 'div', 'lks' );
				links.forEach( ( [ label, href ] ) => lks.append( link( 'a', '', label + ' ↗', href ) ) );
				sec.append( lks );
			}
			if ( s.busy ) {
				sec.append( spinning( s.busy ) );
			}
			( s.rows || [] ).forEach( ( r ) => sec.append( rowOf( r ) ) );
			if ( Array.isArray( s.key ) && s.key.length ) {
				sec.append( keyOf( s.key ) );
			}
			if ( s.text ) {
				sec.append( el( 'div', 'more', s.text ) );
			}
			if ( Array.isArray( s.undo ) && s.undo.length ) {
				sec.append( undoList( s.undo ) );
			}
			if ( Array.isArray( s.actions ) && s.actions.length ) {
				const acts = el( 'div', 'acts' );
				s.actions.forEach( ( a ) => acts.append( button( a ) ) );
				sec.append( acts );
			}
			body.append( sec );
		} );
		drawFoot( panel );
		body.scrollTop = extra ? 0 : scroll;
		polling( panel.poll || 0 );
	}

	/** The product in hand: this page, whichever it is, can become its page. */
	function handBar() {
		const h = state.hand;
		const bar = el( 'div', 'hand' );
		const say = el( 'div' );
		const name = el( 'b', '', MESSAGES.handOn.replace( '%t', h.title ) );
		say.append( name );
		heldCard( h, bar, name );
		const use = el( 'button', 'b pr', MESSAGES.handUse );
		use.type = 'button';
		const stop = el( 'button', 'b', MESSAGES.handOff );
		stop.type = 'button';
		use.addEventListener( 'click', async () => {
			const html = pageCopy();
			if ( ! html ) {
				return;
			}
			const res = await working( use, MESSAGES.saving, () => ask( { type: 'mapping', url: location.href, op: 'set', product: h.product, target: location.href, html, panel: 1 } ) );
			if ( res && res.ok ) {
				state.hand = null;
				state.product = h.product;
				await ask( { type: 'hand', op: 'stop' } );
			}
			answered( res );
		} );
		stop.addEventListener( 'click', async () => {
			state.hand = null;
			await ask( { type: 'hand', op: 'stop' } );
			bar.remove();
		} );
		bar.append( say, use, stop );
		return bar;
	}

	/** The product in hand, asked for once on each page: the bar is drawn again with every panel. */
	let held = null;
	function heldProduct( product ) {
		if ( ! held || held.product !== product ) {
			held = { product, res: ask( { type: 'held', url: location.href, product } ) };
		}
		return held.res;
	}

	/**
	 * Over the bar's name: the product as the store shows it, beside the maker's page, for a last look before Link this
	 * URL (owner 2026-11-06). It stays while the pointer is on the name or on the card, so its link can be reached.
	 */
	function heldCard( h, bar, name ) {
		const LINGER = { open: 150, close: 250 };
		const asked = heldProduct( h.product );
		let card = null;
		let timer = 0;
		const place = () => {
			if ( card ) {
				card.style.top = Math.max( 12, Math.min( bar.getBoundingClientRect().top, window.innerHeight - 12 - card.offsetHeight ) ) + 'px';
			}
		};
		const fill = ( res ) => {
			if ( ! card ) {
				return;
			}
			const d = ( res && res.data ) || {};
			if ( ! res || ! res.ok ) {
				card.replaceChildren( el( 'div', 'v', d.message || MESSAGES.heldFail ) );
				held = null; // the next look asks again
				place();
				return;
			}
			const none = el( 'div', 'none', MESSAGES.heldNoPicture );
			card.replaceChildren( none );
			if ( d.image ) {
				const img = el( 'img' );
				img.alt = '';
				img.referrerPolicy = 'no-referrer';
				img.addEventListener( 'load', () => none.replaceWith( img ) ); // a site's rules can refuse it: the words stay
				img.src = d.image;
			}
			card.append( el( 'div', 't', d.title || h.title ) );
			if ( d.facts ) {
				card.append( el( 'div', 'v', d.facts ) );
			}
			card.append( el( 'div', 'v', d.text || MESSAGES.heldNoText ), el( 'div', 'v', d.linked || '' ) );
			const view = ownLink( d.view || '' );
			if ( view ) {
				card.append( link( 'a', '', MESSAGES.openOn + ' ↗', view ) );
			}
			place();
		};
		const show = () => {
			clearTimeout( timer );
			timer = setTimeout( () => {
				if ( card ) {
					return;
				}
				card = el( 'aside', 'held' );
				card.append( el( 'div', 'v', MESSAGES.heldWait ) );
				card.addEventListener( 'mouseenter', () => clearTimeout( timer ) );
				card.addEventListener( 'mouseleave', hide );
				bar.append( card );
				place();
				asked.then( fill, () => fill( null ) );
			}, LINGER.open );
		};
		const close = () => {
			clearTimeout( timer );
			if ( card ) {
				card.remove();
				card = null;
			}
		};
		const hide = () => {
			clearTimeout( timer );
			timer = setTimeout( close, LINGER.close );
		};
		name.tabIndex = 0;
		name.addEventListener( 'mouseenter', show );
		name.addEventListener( 'mouseleave', hide );
		name.addEventListener( 'focus', show );
		name.addEventListener( 'blur', hide );
		name.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) {
				close();
			}
		} );
	}

	function failed( res ) {
		const d = ( res && res.data ) || {};
		body.replaceChildren();
		foot.replaceChildren();
		body.append(
			d.connect
				? notice( 'warn', d.message, { label: MESSAGES.connect, run: () => ask( { type: 'connect' } ) } )
				: notice( 'error', d.message || MESSAGES.failed, { label: MESSAGES.retry, run: () => load() } )
		);
	}

	/** An action's answer: its message over the panel it came with, or the error over the panel as it is. */
	/** A product's page just set: where its parts are committed, in a tab of its own with the panel on it. */
	function openLinked( x, b ) {
		return act( { kind: 'hand', op: 'visit', url: x.url, product: x.product, title: x.title }, b );
	}

	/** What a link leaves to do, where it was done: nothing moved on the product yet, and it can still be taken back. */
	function linkedNotice( text, x ) {
		const n = notice( 'ok', text );
		const line = el( 'div', 'nl' );
		if ( ! x.here ) {
			const open = el( 'button', 'b pr', MESSAGES.linkedOpen );
			open.type = 'button';
			open.addEventListener( 'click', () => openLinked( x, open ) );
			line.append( open );
		}
		const undo = el( 'button', 'b', MESSAGES.linkedUndo );
		undo.type = 'button';
		undo.addEventListener( 'click', async () => answered( await working( undo, MESSAGES.saving, () => ask( { type: 'mapping', url: location.href, op: 'undo', product: x.product, panel: 1 } ) ) ) );
		line.append( undo );
		n.append( line );
		return n;
	}

	function answered( res, okText ) {
		const d = ( res && res.data ) || {};
		const text = d.message || okText || '';
		if ( state.base && d.panel && ! d.panel.base ) { // the answer is the maker's page's panel: this page's own, with what was said
			delete d.panel;
			load( true ).then( () => body.prepend( notice( res && res.ok ? 'ok' : 'error', text || MESSAGES.failed ) ) );
			return;
		}
		if ( res && res.ok ) {
			if ( d.panel ) {
				draw( d.panel, text ? ( d.linked ? linkedNotice( text, d.linked ) : notice( 'ok', text ) ) : null );
			} else {
				body.prepend( notice( 'ok', text ) );
			}
			return;
		}
		if ( d.panel ) {
			draw( d.panel, notice( 'error', text || MESSAGES.failed ) );
		} else {
			body.prepend( notice( d.connect ? 'warn' : 'error', text || MESSAGES.failed ) );
		}
	}

	async function load( quiet ) {
		if ( ! quiet ) {
			busy( MESSAGES.loading );
		}
		const res = await ask( { type: 'page', url: location.href, product: state.product, site: state.site ? 1 : 0, look: state.look ? 1 : 0 } );
		if ( res && res.ok ) {
			draw( res.data );
		} else if ( ! quiet ) {
			failed( res );
		}
	}

	function stopPolling() {
		window.clearTimeout( state.timer );
		state.timer = 0;
	}

	/** While the server says work is under way, ask again (only while the panel is open). */
	function polling( ms ) {
		stopPolling();
		if ( ms > 0 ) {
			state.timer = window.setTimeout( () => host.style.display !== 'none' && load( true ), Math.max( 2000, ms ) );
		}
	}

	/** The page as drawn, or null (a bot check: said in the panel, nothing sent). */
	function pageCopy() {
		const html = window.__mmiExtPageHtml();
		if ( window.__mmiExtIsWall( html ) ) {
			body.prepend( notice( 'warn', MESSAGES.wall ) );
			return null;
		}
		return html;
	}

	async function working( b, text, run ) {
		const label = b.textContent;
		b.disabled = true;
		b.replaceChildren( el( 'span', 'spin' ), document.createTextNode( text ) );
		try {
			return await run();
		} finally {
			if ( b.isConnected ) {
				b.disabled = false;
				b.textContent = label;
			}
		}
	}

	async function act( a, b ) {
		switch ( a.kind ) {
			case 'look':
				state.look = true;
				await working( b, MESSAGES.working, () => load( true ) );
				break;
			case 'scrape':
				answered( await working( b, MESSAGES.working, () => ask( { type: 'scrape', url: location.href, product: a.product } ) ) );
				break;
			case 'open': {
				const href = ownLink( a.href || '' );
				if ( href ) {
					window.open( href, '_blank', 'noopener' );
				}
				break;
			}
			case 'hand': { // from one of our product pages: its page, or its maker's site with the product in hand
				const res = await ask( { type: 'hand', op: a.op, url: a.url, product: a.product, title: a.title } );
				answered( res, MESSAGES.handOpened );
				break;
			}
			case 'reload':
				load();
				break;
			case 'focus':
				state.product = a.product || 0;
				load();
				break;
			case 'site':
				state.site = true;
				await working( b, MESSAGES.loadingSite, () => load( true ) );
				break;
			case 'go':
				if ( sameSite( a.url ) ) {
					body.prepend( notice( 'ok', MESSAGES.going.trim() ) );
					location.assign( a.url );
				}
				break;
			case 'sitepart':
				forgetReviews();
				answered( await working( b, MESSAGES.working, () => ask( { type: 'sitepart', url: location.href, op: a.op || '', product: state.product } ) ) );
				break;
			case 'links':
				state.reviews.links = { scope: 'links', key: '', c: {}, parts: [], plans: [], data: null, busy: ' ', error: '', open: {}, examples: {}, all: {} };
				showReview( 'links' );
				lookLinks( state.reviews.links );
				break;
			case 'allow': {
				const res = await ask( { type: 'allow', url: location.href } );
				if ( ! res || ! res.ok ) {
					answered( res );
				}
				break;
			}
			case 'readlist': {
				window.__mmiExtSendOnly = 'list';
				const res = await working( b, MESSAGES.opening, () => ask( { type: 'inject', set: 'send' } ) );
				if ( ! res || ! res.ok ) {
					answered( res );
				}
				break;
			}
			case 'snapshot':
				await store( b );
				break;
			case 'write': {
				const html = pageCopy();
				if ( html ) {
					const res = await working( b, MESSAGES.writing, () => ask( { type: 'write', url: location.href, product: a.product, expect: a.expect || [], html } ) );
					answered( res, MESSAGES.writeStarted );
				}
				break;
			}
			case 'undo': {
				const res = await working( b, MESSAGES.undoing, () => ask( { type: 'undo', url: at(), product: a.product, what: a.what } ) );
				answered( res );
				break;
			}
			case 'veto': {
				const res = await working( b, MESSAGES.vetoing, () => ask( { type: 'veto', url: at(), product: a.product, picture: a.picture } ) );
				answered( res );
				break;
			}
			case 'search':
				finder( a );
				break;
			case 'link':
				await linkPage( a, b, false );
				break;
			case 'next': {
				const res = await working( b, MESSAGES.finding, () => ask( { type: 'next', url: location.href, product: a.product || state.product } ) );
				const d = ( res && res.data ) || {};
				if ( res && res.ok && sameSite( d.url ) ) {
					await ask( { type: 'focus', product: d.product, url: d.url, title: d.title } );
					body.prepend( notice( 'ok', ( d.message || '' ) + MESSAGES.going ) );
					location.assign( d.url );
				} else {
					answered( res );
				}
				break;
			}
			case 'send': {
				const res = await working( b, MESSAGES.opening, () => ask( { type: 'inject', set: 'send' } ) );
				if ( ! res || ! res.ok ) {
					answered( res );
				}
				break;
			}
			case 'layout':
				await layout( a, b );
				break;
			case 'startmap': // protocol 8: the site's map, then its layouts; the panel asks again until they show
				state.site = true;
				answered( await working( b, MESSAGES.starting, () => ask( { type: 'site', url: location.href, op: a.op || 'start', product: state.product } ) ) );
				break;
			case 'map':
			case 'pictures': {
				window.__mmiExtPickFor = { product: a.product || state.product, title: a.title || '', part: a.part || '' };
				const res = await working( b, MESSAGES.opening, () => ask( { type: 'inject', set: a.kind === 'map' ? 'mapper' : 'pictures' } ) );
				if ( res && res.ok ) {
					stopPolling();
					forgetReviews();
					folded( true );
				} else {
					answered( res );
				}
				break;
			}
		}
	}

	/** A layout's part (plan §13 S2b). plan: its review, where it is committed; undo and confirm: one call. */
	async function layout( a, b ) {
		const base = { type: 'layout', url: location.href, op: a.op || '', part: a.part || '', product: state.product };
		if ( a.op === 'plan' ) {
			reviewPart( a );
			return;
		}
		forgetReviews();
		answered( await working( b, MESSAGES.working, () => ask( base ) ) );
	}

	/** An address on the site this tab is on (Next only moves within it). */
	function sameSite( u ) {
		try {
			const bare = ( h ) => h.toLowerCase().replace( /^www\./, '' );
			const x = new URL( u );
			return /^https?:$/.test( x.protocol ) && bare( x.hostname ) === bare( location.hostname );
		} catch ( e ) {
			return false;
		}
	}

	/* ── Linking this page to a product ────────────────────────────────── */

	/** The product search over the panel's content: the site's brands' products, each a link action. */
	function finder( a ) {
		const old = body.querySelector( '.find' );
		if ( old ) {
			old.querySelector( 'input' ).focus();
			return;
		}
		const box = el( 'div', 'find' );
		const input = el( 'input' );
		input.type = 'search';
		input.placeholder = a.placeholder || '';
		input.setAttribute( 'aria-label', MESSAGES.searchLabel );
		const list = el( 'div' );
		box.append( el( 'h2', '', a.label || '' ), input, list );
		body.prepend( box );
		body.scrollTop = 0;
		input.focus();
		let timer = 0;
		let seq = 0;
		input.addEventListener( 'input', () => {
			window.clearTimeout( timer );
			timer = window.setTimeout( async () => {
				const q = input.value.trim();
				const mine = ++seq;
				if ( q.length < 2 ) {
					list.replaceChildren();
					return;
				}
				list.replaceChildren( spinning( MESSAGES.searching ) );
				const res = await ask( { type: 'products', url: location.href, q } );
				if ( mine !== seq ) {
					return; // a newer search is on its way
				}
				const rows = ( res && res.ok && res.data.rows ) || [];
				list.replaceChildren( ...( rows.length ? rows.map( rowOf ) : [ notice( res && res.ok ? '' : 'error', res && res.ok ? MESSAGES.noMatch : ( res && res.data && res.data.message ) || MESSAGES.failed ) ] ) );
			}, 400 );
		} );
	}

	/** Link this page to a product; a verified page is replaced only after the second click the server asks for. */
	async function linkPage( a, b, confirm ) {
		const html = pageCopy();
		if ( ! html ) {
			return;
		}
		const res = await working( b, MESSAGES.linking, () => ask( { type: 'link', url: location.href, product: a.product, confirm: confirm ? 1 : 0, html } ) );
		const d = ( res && res.data ) || {};
		if ( res && res.status === 409 && d.confirm ) {
			const n = notice( 'warn', d.confirm, { label: d.label || MESSAGES.linking, run: ( e ) => linkPage( a, e.currentTarget, true ) } );
			const row = b.closest( '.row' );
			if ( row ) {
				row.after( n );
			} else {
				body.prepend( n );
			}
			return;
		}
		if ( res && res.ok ) {
			state.product = a.product;
		}
		answered( res );
	}

	/* ── Storing the page as drawn ─────────────────────────────────────── */

	async function store( b ) {
		const html = pageCopy();
		if ( ! html ) {
			return;
		}
		const res = await working( b, MESSAGES.storing, () => ask( { type: 'snapshot', url: location.href, html, product: state.product } ) );
		const d = ( res && res.data ) || {};
		const num = ( n ) => Number( n || 0 ).toLocaleString();
		if ( res && res.ok && d.dry ) { // the browser check's copy asks for a check only
			body.prepend( notice( 'ok', d.message || MESSAGES.checked.replace( '%n', d.would_store ).replace( '%t', num( d.text_chars ) ).replace( '%i', d.images ) ) );
		} else if ( res && res.ok ) {
			const text = d.message || ( d.stored // the server's words first: a choice bundle's page says what its choices gave
				? MESSAGES.stored.replace( '%n', d.stored ).replace( '%t', num( d.text_chars ) ).replace( '%i', d.images )
				: MESSAGES.kept.replace( '%t', num( d.kept_reads && d.kept_reads.text ) ).replace( '%i', ( d.kept_reads && d.kept_reads.pictures ) || 0 ) );
			const done = notice( d.stored || d.message ? 'ok' : 'warn', text );
			if ( d.panel ) {
				draw( d.panel, done );
			} else {
				body.prepend( done );
			}
		} else {
			answered( res );
		}
	}

	sheet.addEventListener( 'keydown', ( e ) => {
		if ( e.key === 'Escape' ) {
			hideReview();
		}
	} );

	report();
	Promise.all( [ ask( { type: 'perm', url: location.href } ), ask( { type: 'hand', op: 'get' } ) ] ).then( ( [ res, hand ] ) => {
		state.allowed = ! res || ! res.ok || !! res.data.allowed;
		state.hand = hand && hand.ok && hand.data ? hand.data : null;
	} ).finally( () => load() );
}() );
