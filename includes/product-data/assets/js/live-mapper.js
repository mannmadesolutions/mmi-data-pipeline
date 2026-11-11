/**
 * Live mapper panel (LiveMapper on the server; owner 2026-10-11: map a vendor page the way Web
 * Scraper does, on the live page). Loaded into the vendor's page by the MMI Product Data Chrome extension, after
 * map-select.js (the selector rules).
 *
 * The panel lives in a shadow root, so the vendor's styles can't reach it and its styles can't
 * reach the page. Outlines are drawn on a fixed layer of its own; the page's elements are never
 * changed. Choose a part, press Pick, and click elements: a click adds an element or takes a picked one out
 * (or, switched to Replace, replaces the picks; Shift-click does the other), P / C walk the last pick out to its
 * parent and back, Escape stops. A click picks what it hits, even an element that gives the part nothing (it says
 * so; owner 2026-10-31, "Am I in charge or is the UX?"). A pick that looks wrong for its part
 * (a full-width banner as a product picture, a menu as features, a cookie notice) is shown with a
 * warning and can still be saved; the warning is kept with the part (owner 2026-10-11).
 *
 * The extension carries this file and map-select.js (ExtBridge::BUNDLED) and gives its bridge
 * (window.mmptLiveMapperBridge, browser-extension/mapper.js): ask( op, payload ) answers through the extension, with
 * the login it holds; pageHtml() is the extension's copy of the page, saved() and closed() tell its panel. Tests and
 * saves send the page as the browser shows it, so the server tests the selector on what you see. (The "Map with
 * store" bookmarklet and its mapper window on the store were retired 2026-11-03: the extension replaced them.)
 *
 * Layouts (the extension's hello names the page's layout, plan §13 S2): picks can be tested and saved for this page's
 * layout only (MapTypes: the layout's own pick, confirmed from the extension's layout section) or for the whole site. A layout of two or
 * more pages is the default, so a pick made on one page reaches the pages built like it.
 *
 * A pick says what it would do before it is saved (owner 2026-11-04: "calculate these exposures at the time of selection"):
 * a moment after a pick settles the mapper asks the server (effect, ExtBridge::map_effect()) what committing it would do to
 * the products it reaches, the panel's commit report, slice by slice. A new pick drops the old answer.
 *
 * The page stays usable while picking (owner 2026-11-04: "they're on another tab. Should webpage navigation be allowed when
 * selecting elements?"). A click on a text tab opens it rather than picking its label, so what is in it can be picked;
 * Browse (or the B key) gives the page its clicks back for anything else: an accordion, Read more, a slider, another page.
 *
 * Embedded frames can be picked (owner 2026-11-04: "selection plays the video instead of selecing it"). A click in a frame
 * belongs to the frame's own page and never reaches this one, so while picking frames take no clicks (a style sheet of the
 * mapper's, not a change to the page or its stored copy) and pageTarget() finds the frame under the pointer. Browse lets them
 * play.
 *
 * What is saved stays in sight (owner 2026-11-04: "the saved settings are not displayed, making the process confusing"):
 * a part with a saved pick, the layout's own or the site's, opens with it outlined on the page and said in the panel, and
 * Save leaves it there. Hovering a pick in the panel's list outlines it on the page.
 *
 * The extension's panel rows (protocol 6) open the mapper on their part (BRIDGE.part), picking from the first moment,
 * before the server has answered, so a click never reaches the page (owner 2026-11-03: "I was expecting to be able to
 * select Description elements straight away"). Opened that way it shows that one part and Back, not the list of parts:
 * the row already chose. Pick a part on this page, which chooses nothing, keeps the list.
 *
 * Warnings point at what set them off (owner 2026-11-02: "links to their DOM locations as well as on-screen
 * alerts/styling identifying them, plus the user needs the ability to either cancel the warning … and the code should
 * be able to filter keywords out"): each lists its spots on the page, outlined in red with a label, with Show (scrolls
 * to the next one) and Dismiss; shop or site text can be left out on this site (the reader's per-site list of lines,
 * MapCheck, through the extension).
 *
 * Picks are judged against what the reader has (owner 2026-11-04, a maker site whose pages build in the browser): the page as delivered, or, where the
 * server says the pages of this layout are stored as a browser built them (LiveMapper::reads()), our stored copy of this
 * page. Its classes are the ones a selector may use, and something missing from it is what the warning names.
 *
 * Pictures are judged at full size (owner 2026-11-02: "selecting thumbnails will yield low resolutions, however most
 * sites have an easy to guess/determine image URL structure"): each picked picture's full-size copy (map-select.js
 * fullSize(), the server's rules) is loaded once and measured, so a 100 px thumbnail of a 1600 px picture isn't small.
 */
( function () {
	'use strict';

	const BRIDGE = window.mmptLiveMapperBridge;
	const SEL = window.mmptMapSelect;
	if ( ! BRIDGE || ! BRIDGE.ask || ! SEL || window.mmptLiveMapper ) {
		return;
	}

	const PARTS = [ 'pictures', 'description', 'requirements', 'manual', 'videos', 'audio', 'disclaimer', 'contents' ]; // VendorMaps::FIELDS without NOT_MAPPED (features, version: the generic reader); contents names other products (RELATIONS)
	const COLORS = { pictures: '#2f7de1', description: '#2e7d32', features: '#00897b', requirements: '#8d6e63', version: '#5c6bc0', manual: '#c2185b', videos: '#e65100', audio: '#6a1b9a', disclaimer: '#ad5a00', contents: '#455a64', extra: '#d63638', hover: '#1d2327' };
	const LIMITS = { effectMs: 900, tabChars: 60, picks: 60, boxes: 80, editMs: 350, previewChars: 160, thumbs: 4, minPicture: 300, spots: 12, flashMs: 1800, lineChars: 200, measure: 60 };
	const THRESHOLDS = { bannerAspect: 1.6, bannerShare: 0.6, menuShare: 0.6, menuItemChars: 40 };
	const KEYS = { wider: 'p', narrower: 'c', browse: 'b', stop: 'Escape' };
	const ADMIN_PATH = '/wp-admin/admin.php?page=mmi-data-pipeline&pipeline_tab=workbench&wb_sec=maps'; // + &wb_site=<host>: the Workbench's Site maps, this site open
	const CHROME_AREAS = 'header, nav, footer, aside, [role="navigation"], [role="banner"], [role="contentinfo"], [id*="cookie" i], [class*="cookie" i], [id*="consent" i], [class*="consent" i]';
	const SHOP_TEXT = /\b(cookies?|privacy policy|newsletter|subscribe|add to cart|checkout|sign in|log in|free shipping)\b/i;
	const REQ_WORDS = /\b(mac|macos|os x|windows|win\s?1[01]|ram|cpu|processor|ghz|\d+\s?gb|apple silicon|intel|amd)\b/i;
	const LOGO_RE = /logo|icon|sprite|badge|payment|avatar|flag/i;
	const OVERLAY_RE = /cookie|consent|usercentrics|onetrust|didomi|cookiebot|gdpr|\bcmp\b|klaviyo|needsclick|privy|justuno|optinmonster|omnisend|wisepops|newsletter/i; // consent managers and sign-up pop-ups that cover the page

	const MESSAGES = {
		pickLimit: 'At most %n% picks for one part. Pick a block that holds several of them instead.',
		title: 'Product Data mapper',
		connecting: 'Connecting to the mapper window…',
		noAnswer: 'The store didn’t answer. Check the extension’s connection and try again.',
		noMap: 'This site has no vendor map: none of our products has a verified page on it.',
		thisPage: { 0: 'None of our products uses this page.', 1: '1 of our products uses this page.', n: '%n% of our products use this page.' },
		status: { draft: 'Draft ready', confirmed: 'Confirmed', off: 'Switched off', broken: 'Stopped matching', auto: 'Reads fine', needs: 'Needs a map', refused: 'Refuses the server' }, // VendorMaps::status_of(), as the Vendor maps pills name them
		notMapped: 'Not mapped',
		notPicked: 'Nothing picked yet',
		back: '‹ Back',
		backTitle: 'Back to the panel',
		savedBy: { person: 'Set by you', auto: 'Drafted' },
		live: 'on the live page',
		works: 'found on %found% of %of% stored pages',
		warnSaved: 'Saved with a warning: %w%',
		pick: 'Pick on this page',
		pickAgain: 'Pick again',
		showSaved: 'Show saved',
		hideSaved: 'Hide saved',
		savedForLayout: 'Saved for layout %l%',
		savedForSite: 'Saved for the whole site',
		savedNone: 'It finds nothing on this page as it is now. Test on stored pages says what it finds on the pages the reader uses.',
		hint: 'Click %part% on the page: each click adds an element, or takes a picked one back out. Tabs open as usual. P wider, C narrower, B browse; Escape stops.',
		hintReplace: 'Click %part% on the page: each click replaces what is picked. Tabs open as usual. P wider, C narrower, B browse; Escape stops.',
		browsing: 'Browsing: clicks work on the page as usual, so you can open what you need. Press Add or Replace, or B, to pick again. A link to another page closes the mapper, and picks not saved go with it.',
		tabOpened: 'Opened “%name%”. Click what you want in it.',
		effectLooking: 'Looking at what this does: %n% of %t% products…',
		effectBusy: 'The server is busy, so what this does to its products can wait: the commit report still says it.',
		opensTab: 'Opens this tab',
		clicks: 'Clicks',
		modeAdd: 'Add',
		modeReplace: 'Replace',
		modeBrowse: 'Browse',
		modeTitle: 'What a click does with the picks. Shift-click does the other.',
		gaveNothing: 'Picked, but it gives %part% nothing on its own. Click it again to take it out, or press P for the box around it.',
		shadow: 'That element is inside a page component the reader can’t see. Pick the element around it.',
		overlay: 'A cookie notice or sign-up pop-up is in the way. Stop picking, close it on the page, then pick again.',
		picks: 'Picked (%n%)',
		selector: 'Selector',
		badSelector: 'The browser doesn’t understand that selector.',
		gives: 'Gives %part%: %what%',
		takesExtra: 'Also takes %n% element(s) unlike your picks (red dashes).',
		alikeN: 'Also reads %n% more like your picks, beside them (dashed, “also read”).',
		wider: 'Wider (P)',
		narrower: 'Narrower (C)',
		test: 'Test on stored pages',
		testing: 'Testing on the stored pages…',
		tested: 'Found on %found% of %of% stored pages.',
		checking: 'Checking this pick across the site’s stored pages…',
		checkHead: 'Across the site',
		checkBusy: 'The server is busy; the across-the-site check is skipped this time.',
		sample: 'On this page the reader gets: %detail%',
		save: 'Save for %part%',
		saveWarn: 'Save with warning',
		clearPick: 'Clear this pick',
		undoPick: 'Undo last change',
		undoTitle: 'Put this part’s pick for the site back as it was before its last change (%at% UTC)',
		undone: '%part%’s pick is back as it was before its last change.',
		clearSure: 'Click again to clear it',
		clearLayoutSure: 'Click again: its pages go back to the site’s pick',
		cleared: '%part%’s pick is cleared.',
		single: { pictures: 'Only this product? Pick single pictures', videos: 'Only this product? Pick single videos', audio: 'Only this product? Pick single audio demos' },
		saving: 'Saving…',
		saved: '%part% saved: found on %found% of %of% stored pages. When the site’s map looks right, confirm it with Fill in Product Data → Site maps.',
		savedContents: '%part% saved: it names products on %found% of %of% bundle pages. It writes nothing to products. %said%',
		stop: 'Stop picking',
		pageMode: 'Use this page’s requirements for every product',
		pageAway: 'Only what you pick here is read, for all of %h%’s products. Leave out what doesn’t apply to them. If a second page has more, pick there too: both are read.',
		layoutPage: 'This page is layout %l% (%n% pages built like it).',
		layoutShort: 'layout %l%',
		scope: 'Save for',
		scopeLayout: 'Layout %l% only (%n% pages)',
		scopeSite: 'The whole site',
		layoutOwn: 'Layout %l%’s own pick · found on %found% of %of% of its pages',
		testedLayout: 'Found on %found% of %of% of layout %l%’s pages.',
		savedLayout: '%part% saved for layout %l%: found on %found% of %of% of its pages. Its products get it when you write it to them (the extension panel’s Layout %l% section) or confirm layout %l%; the rest of the site keeps the site map.',
		openMaps: 'Open on the Product Data page',
		proposedInfo: 'Proposed by Claude · found on %found% of %of%',
		proposalDone: { approved: 'Claude’s proposal approved', rejected: 'Claude’s proposal rejected' },
		inspectHead: 'Claude’s proposal for %part% (%scope%)',
		propSite: 'the whole site',
		propLayout: 'layout %l%',
		inspectFound: 'Found on %found% of %of% stored pages.',
		inspectNote: 'Claude’s note: %n%',
		inspectReads: 'What it reads on other products:',
		inspectNothing: 'nothing',
		inspectShow: 'Show it on this page',
		inspectHere: 'On this page it reads:',
		inspectHereNone: 'On this page it reads nothing.',
		inspectDelivered: 'Only in the page as delivered: the shop’s scripts move or hide it, and the server reads it there.',
		inspectSameFile: 'Your pick gives the same file.',
		inspectOtherFile: 'Your pick gives a different file.',
		iconLink: 'an icon link, no words',
		linkTo: '→ %where%',
		linkManual: 'Read as a manual link.',
		linkNotManual: 'Not read as a manual: no manual words in its text or address.',
		linksIn: 'Links inside: %n%',
		approve: 'Approve',
		reject: 'Reject',
		deciding: 'Saving your decision…',
		approved: '%part% approved: it is now %scope%’s criteria (found on %found% of %of% stored pages). Commit it from the extension panel to write it to products.',
		rejected: '%part%: Claude’s proposal rejected. Pick it yourself, or leave it to the general reader.',
		waiting: ' Claude proposed criteria for %n% part(s): choose one to inspect it on this page.',
		move: 'Move panel to the other side',
		close: 'Close the mapper',
		warnHead: 'Looks wrong for %part%:',
		show: 'Show',
		showN: 'Show %i%/%n%',
		dismiss: 'Dismiss',
		dismissTitle: 'You checked it: save without this warning',
		leaveOut: 'Leave these lines out on this site',
		useDelivered: 'Use the delivered copy (%what%)',
		useDeliveredTitle: 'The same content where the page has it before its scripts run: %sel%',
		wholeGallery: 'This gallery is drawn by a script, so wherever you click in it, the reader takes all its pictures from the gallery’s own data, at full size.',
		stored: {
			built: 'it isn’t in our stored copy of this page, so the reader would find nothing there.',
			builtPart: '%sel% isn’t in our stored copy of this page, so the reader gets nothing from it.',
			spot: 'not in the stored copy',
			use: 'Use the stored copy (%what%)',
			useTitle: 'The same content where our stored copy has it: %sel%',
			inspect: 'Only in our stored copy: the live page has moved or hidden it, and the server reads it there.',
		},
		leaveOutTitle: 'The description reader skips these lines on every page of this site from now on (Product Data’s list of lines to leave out)',
		leftOut: '%n% line(s) left out on this site: the reader skips them on every page of it.',
		fullSizes: 'Read at full size: %n% different picture(s)%w%. A thumbnail is read as its full picture (the shop’s own full-size address), so a slide and its thumbnail count once.',
		fullWidest: ', up to %w% px wide',
		measuring: 'Measuring the pictures at full size…',
		plainText: 'Descriptions are written as plain text: links, buttons and the page’s styling aren’t kept, only paragraphs, headings and lists.',
		spot: { saved: 'saved', chrome: 'header / menu / footer', banner: 'banner', logo: 'logo or icon', extra: 'not picked', alike: 'also read', built: 'built by scripts' },
		warn: {
			chrome: 'part of it sits in the site’s header, menu, footer or a cookie notice.',
			banner: 'it looks like a full-width banner (a hero or lifestyle photo), not a product picture.',
			small: '%n% of %of% are under 300 px wide even at full size (the smallest is %w% px).',
			logo: 'it looks like a logo or icon.',
			shop: 'it contains shop or site text (cart, cookies, newsletter, sign-in).',
			menu: 'it looks like a menu: most items are short links.',
			noReq: 'there are no Mac, Windows, RAM or CPU words in it.',
			short: 'it gives less than the reader needs (%what%).',
			extra: 'it also takes %n% element(s) unlike your picks.',
			built: 'it isn’t in the page as delivered: the page’s scripts build it after loading, and stored pages are read as delivered, so it would find nothing there.',
			builtPart: '%sel% isn’t in the page as delivered (the page’s scripts build it), so stored pages read nothing from it.',
		},
		parts: { pictures: 'Pictures', description: 'Description', features: 'Features', requirements: 'System requirements', version: 'Version', manual: 'Manual', videos: 'Videos', audio: 'Audio demos', disclaimer: 'Disclaimer', contents: 'Bundle contents' },
	};

	const CSS = `
:host { all: initial }
.layer { position: fixed; inset: 0; pointer-events: none; z-index: 2147483646 }
.box { position: fixed; left: 0; top: 0; width: var(--w); height: var(--h); transform: translate(var(--x), var(--y)); border: 3px solid var(--c); box-sizing: border-box; border-radius: 2px }
.box.extra { border-style: dashed }
.box.flag { border: 2px dashed #d63638; background: rgba(214,54,56,.07) }
.box.flash { border-width: 4px; background: rgba(214,54,56,.18); animation: mmpt-lm-flash .5s ease-in-out 3 }
.box[data-label]:not([data-label=""])::after { content: attr(data-label); position: absolute; left: -2px; top: -19px; max-width: 260px; overflow: hidden; text-overflow: ellipsis; padding: 0 6px; border-radius: 3px; background: var(--c); color: #fff; font: 600 11px/17px -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; white-space: nowrap }
@keyframes mmpt-lm-flash { 50% { background: rgba(214,54,56,.04) } }
.box.saved { border-style: dotted }
.linkbtn { display: block; margin: 8px 0 0; padding: 0; border: 0; background: none; color: #2271b1; font: inherit; font-size: 12.5px; text-align: left; cursor: pointer }
.linkbtn:hover { text-decoration: underline }
.effect { padding: 7px 9px; border-left: 4px solid #2271b1; background: #f0f6fc; font-size: 12.5px }
.savedbox { padding: 7px 9px; border: 1px solid #c5d9ed; border-radius: 4px; background: #f6faff; display: grid; gap: 3px }
.box.hover { border-width: 2px; background: rgba(47,125,225,.08) }
.tip { position: fixed; left: 0; top: 0; transform: translate(var(--x), var(--y)); max-width: 380px; background: #1d2327; color: #fff; font: 12px/1.4 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 6px 8px; border-radius: 4px; white-space: pre-wrap; word-break: break-word }
.tip[hidden], [hidden] { display: none !important }
.panel { position: fixed; top: 12px; right: 12px; width: 370px; max-height: calc(100vh - 24px); overflow: auto; pointer-events: auto; z-index: 2147483647; background: #fff; color: #1d2327; font: 13px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; border: 1px solid #c3c4c7; border-radius: 6px; box-shadow: 0 10px 30px rgba(0,0,0,.3); text-align: left }
.panel.left { right: auto; left: 12px }
.head { display: flex; align-items: center; gap: 8px; padding: 9px 12px; background: #3f7a2b; color: #fff; position: sticky; top: 0; z-index: 1 }
.head b { flex: 1; font-size: 13.5px }
.head .host { font-weight: 400; opacity: .85 }
.icon { background: transparent; border: 0; color: inherit; cursor: pointer; font: 16px/1 inherit; padding: 2px 6px; border-radius: 3px }
.icon:hover { background: rgba(255,255,255,.18) }
.icon.back { font-size: 13px; white-space: nowrap; border: 1px solid rgba(255,255,255,.55) }
.body { padding: 10px 12px; display: grid; gap: 10px }
.note { padding: 6px 9px; border-left: 4px solid #2271b1; background: #f0f6fc; font-size: 12.5px }
.note.error { border-color: #d63638; background: #fcf0f1 }
.note.success { border-color: #3f7a2b; background: #edf7ea }
.note.warning { border-color: #dba617; background: #fcf9e8 }
/* Work running in the background: the suite's .mmi-loading shape (mmi-suite-common.css can't reach a shadow root) */
.note.busy { display: flex; align-items: center; gap: 7px }
.spin { flex: none; width: 14px; height: 14px; background: #3f7a2b; -webkit-mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 640 640'%3E%3Cpath d='M286.7 96.1C291.7 113 282.1 130.9 265.2 135.9C185.9 159.5 128.1 233 128.1 320C128.1 426 214.1 512 320.1 512C426.1 512 512.1 426 512.1 320C512.1 233.1 454.3 159.6 375 135.9C358.1 130.9 348.4 113 353.5 96.1C358.6 79.2 376.4 69.5 393.3 74.6C498.9 106.1 576 204 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320C64 204 141.1 106.1 246.9 74.6C263.8 69.6 281.7 79.2 286.7 96.1z'/%3E%3C/svg%3E") center / contain no-repeat; mask: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 640 640'%3E%3Cpath d='M286.7 96.1C291.7 113 282.1 130.9 265.2 135.9C185.9 159.5 128.1 233 128.1 320C128.1 426 214.1 512 320.1 512C426.1 512 512.1 426 512.1 320C512.1 233.1 454.3 159.6 375 135.9C358.1 130.9 348.4 113 353.5 96.1C358.6 79.2 376.4 69.5 393.3 74.6C498.9 106.1 576 204 576 320C576 461.4 461.4 576 320 576C178.6 576 64 461.4 64 320C64 204 141.1 106.1 246.9 74.6C263.8 69.6 281.7 79.2 286.7 96.1z'/%3E%3C/svg%3E") center / contain no-repeat; animation: spin .8s linear infinite }
@keyframes spin { to { transform: rotate(360deg) } }
.muted { color: #646970; font-size: 12px }
.checks { display: grid; gap: 4px; margin-top: 4px }
.checks .ex { display: flex; flex-wrap: wrap; gap: 2px 8px; margin-top: 2px; font-size: 11.5px }
.checks .ex a { color: #2271b1; text-decoration: underline; overflow-wrap: anywhere }
.inspect { display: grid; gap: 6px; padding: 8px 10px; border: 1px solid #c5d9ed; border-radius: 4px; background: #f0f6fc }
.inspect code { word-break: break-all }
.inspect .samples { margin: 0; padding-left: 16px; font-size: 12px; display: grid; gap: 3px }
.part .prop { grid-column: 2; font-size: 12px; color: #6a1b9a }
.badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 1px 7px; border-radius: 999px; background: rgba(255,255,255,.22) }
.parts { display: grid; gap: 4px }
.part { display: grid; grid-template-columns: 10px 1fr; gap: 2px 8px; align-items: start; padding: 6px 8px; border: 1px solid #dcdcde; border-radius: 4px; cursor: pointer; background: #fff; text-align: left; font: inherit; color: inherit }
.part:hover { background: #f6f7f7 }
.part.lone { cursor: default }
.part.on { border-color: #3f7a2b; box-shadow: inset 0 0 0 1px #3f7a2b; background: #f6faf4 }
.dot { width: 10px; height: 10px; border-radius: 50%; background: var(--c); margin-top: 4px }
.part .name { font-weight: 600 }
.part .info { grid-column: 2; font-size: 12px; color: #646970 }
.part .warned { grid-column: 2; font-size: 12px; color: #996800 }
code { font: 11.5px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; background: #f0f0f1; padding: 1px 4px; border-radius: 3px; word-break: break-all }
.work { border-top: 1px solid #dcdcde; padding-top: 10px; display: grid; gap: 8px }
.row { display: flex; flex-wrap: wrap; gap: 6px; align-items: center }
.modes { gap: 0 }
.modes .muted { margin-right: 8px }
.seg { border: 1px solid #8c8f94; background: #fff; color: #1d2327; font: inherit; font-size: 12.5px; padding: 3px 12px; cursor: pointer }
.seg:first-of-type { border-radius: 3px 0 0 3px }
.seg:last-of-type { border-radius: 0 3px 3px 0; border-left: 0 }
.seg.on { background: #3f7a2b; border-color: #3f7a2b; color: #fff }
button.btn { font: 600 12.5px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 7px 10px; border-radius: 3px; border: 1px solid #3f7a2b; background: #fff; color: #3f7a2b; cursor: pointer }
button.btn:hover { background: #f0f6ec }
button.btn.primary { background: #3f7a2b; color: #fff }
button.btn.warn { background: #dba617; border-color: #b78a00; color: #1d2327 }
button.btn.quiet { border-color: #c3c4c7; color: #1d2327; font-weight: 500 }
button.btn[disabled] { opacity: .45; cursor: not-allowed }
button:focus-visible, input:focus-visible { outline: 2px solid #2271b1; outline-offset: 1px }
input.sel { width: 100%; box-sizing: border-box; font: 12px ui-monospace, SFMono-Regular, Menlo, monospace; padding: 6px 8px; border: 1px solid #8c8f94; border-radius: 3px; color: #1d2327; background: #fff }
label.check { display: flex; gap: 6px; align-items: center; font-size: 12.5px }
.picklist { display: grid; gap: 6px; margin: 0; padding: 0; list-style: none }
.picklist li { border: 1px solid #dcdcde; border-radius: 4px; padding: 6px 8px; display: grid; gap: 4px; font-size: 12px }
.picklist .top { display: flex; justify-content: space-between; gap: 6px }
.thumbs { display: flex; gap: 4px; flex-wrap: wrap }
.thumbs img { width: 56px; height: 56px; object-fit: contain; background: #fff; border: 1px solid #dcdcde }
.warnbox { background: #fcf9e8; border: 1px solid #dba617; border-radius: 4px; padding: 7px 9px; font-size: 12.5px }
.warnbox ul { margin: 4px 0 0; padding-left: 18px }
.warnbox li { margin: 0 0 4px }
.warnbox .wa { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 3px }
.warnbox .wa button { min-height: 22px; padding: 0 8px; border: 1px solid #996800; border-radius: 3px; background: #fff; color: #6d4c00; font: inherit; font-size: 11.5px; cursor: pointer }
.warnbox .wa button:hover { background: #fff8e1 }
.foot { border-top: 1px solid #dcdcde; padding-top: 8px; display: flex; justify-content: space-between; gap: 8px; font-size: 12.5px }
.foot a { color: #2271b1 }
@media (prefers-reduced-motion: no-preference) { .part, button.btn { transition: background-color .12s } }
`;

	/* ── Helpers ───────────────────────────────────────────────────────── */

	const fill = ( tpl, vars ) => tpl.replace( /%(\w+)%/g, ( m, k ) => ( k in vars ? String( vars[ k ] ) : m ) );
	const h = ( tag, attrs = {}, ...kids ) => {
		const el = document.createElement( tag );
		Object.entries( attrs ).forEach( ( [ k, v ] ) => {
			if ( v === undefined || v === null || v === false ) {
				return;
			}
			if ( k === 'text' ) {
				el.textContent = v;
			} else if ( k === 'class' ) {
				el.className = v;
			} else if ( k.startsWith( 'on' ) ) {
				el.addEventListener( k.slice( 2 ), v );
			} else {
				el.setAttribute( k, v === true ? '' : v );
			}
		} );
		kids.flat().forEach( ( c ) => c !== null && c !== undefined && c !== false && el.append( c ) );
		return el;
	};
	const partName = ( k ) => MESSAGES.parts[ k ] || k;
	const topOnly = ( els ) => els.filter( ( el ) => ! els.some( ( o ) => o !== el && o.contains( el ) ) );
	const textOf = ( els ) => topOnly( els ).map( ( el ) => el.textContent.replace( /\s+/g, ' ' ).trim() ).join( ' ' );

	/* ── State ─────────────────────────────────────────────────────────── */

	const fromRow = PARTS.includes( BRIDGE.part ); // a panel row chose the part: only that part, picking at once

	const state = {
		map: null, products: 0, host: location.hostname.replace( /^www\./, '' ), part: fromRow ? BRIDGE.part : 'pictures', focus: fromRow, greeted: false, ref: 'delivered',
		picking: fromRow, mode: 'add', proposals: {}, picks: [], stack: [], sel: '', custom: false, matched: [], extras: [], alike: [], layout: null, scope: 'site', dismissed: new Set(), dismissedFor: '', shown: {}, flash: null, skipped: new Set(),
		hover: null, tipText: '', browse: false, effect: null, showSaved: false, saved: [], pageMode: false, away: '',
		note: { text: MESSAGES.connecting, type: 'info', busy: true }, test: null, busy: false, left: false, editTimer: 0,
	};

	/* ── Talking to the store (the extension's bridge) ───────────────────── */

	const ask = ( op, payload = {} ) => BRIDGE.ask( op, payload );

	/** The page as the browser shows it, for the page store: scripts and styles out, the pictures the browser chose written in. */
	function pageHtml() {
		if ( BRIDGE.pageHtml ) {
			return BRIDGE.pageHtml();
		}
		const clone = document.documentElement.cloneNode( true );
		const live = Array.from( document.images );
		const copies = Array.from( clone.querySelectorAll( 'img' ) );
		live.forEach( ( img, i ) => {
			const c = copies[ i ];
			const src = c && c.getAttribute( 'src' ) || '';
			if ( c && img.currentSrc && ( ! src || src.startsWith( 'data:' ) ) ) {
				c.setAttribute( 'src', img.currentSrc ); // lazy pictures: what the browser actually loaded
			}
		} );
		clone.querySelectorAll( '#mmpt-live-mapper, script:not([type="application/ld+json"]), style, noscript, link[rel="stylesheet"], link[rel="preload"], svg' ).forEach( ( e ) => e.remove() );
		clone.querySelectorAll( 'iframe' ).forEach( ( f ) => SEL.VIDEO_SRC.test( f.getAttribute( 'src' ) || '' ) || f.remove() );
		return `<!DOCTYPE html>${ clone.outerHTML }`;
	}

	/* ── Judging picks ─────────────────────────────────────────────────── */

	function gives() {
		return SEL.givesOf( state.part, state.matched.length ? state.matched : state.picks );
	}

	/** Why the picks look wrong for the part (saved anyway if the person chooses; owner 2026-10-11). */
	/**
	 * What looks wrong with the part as picked: { key, text, spots: [ { el, label } ] }, its spots being what set it off
	 * on the page. Dismissed ones are left out (allWarnings() has them all).
	 */
	function warnings() {
		if ( state.dismissedFor !== state.sel ) { // a new pick: its warnings are its own
			state.dismissed = new Set();
			state.dismissedFor = state.sel;
		}
		return allWarnings().filter( ( x ) => ! state.dismissed.has( x.key ) );
	}

	/** The smallest elements under $els whose text holds shop or site words, each with the words found. */
	function shopSpots( els ) {
		const out = [];
		topOnly( els ).forEach( ( top ) => {
			[ top, ...top.querySelectorAll( '*' ) ].forEach( ( e ) => {
				const m = SHOP_TEXT.exec( e.textContent );
				if ( m && ! Array.from( e.children ).some( ( c ) => SHOP_TEXT.test( c.textContent ) ) && ! state.skipped.has( lineKey( e ) ) ) {
					out.push( { el: e, label: m[ 0 ] } );
				}
			} );
		} );
		return out.slice( 0, LIMITS.spots );
	}

	/** A line as the server's list of lines to leave out compares it (MapCheck::text_key(), roughly). */
	const lineKey = ( e ) => e.textContent.replace( /\s+/g, ' ' ).trim().toLowerCase().replace( /[\s.…:»›→>!]+$/, '' );

	/**
	 * A picture's width as the reader takes it: its full-size copy (SEL.bestSrc(): a zoom link, srcset, the shop's
	 * full-size address), loaded once to measure; null while it loads. A full-size address that doesn't load: the
	 * picture as shown.
	 */
	const fullWidths = new Map(); // address → width, -1 failed, null loading
	let measured = 0;
	function realWidth( img ) {
		const shown = img.currentSrc || img.src;
		const full = SEL.bestSrc( img );
		if ( ! full || full === shown ) {
			return img.naturalWidth || null;
		}
		if ( ! fullWidths.has( full ) ) {
			fullWidths.set( full, null );
			const probe = new Image();
			probe.referrerPolicy = 'no-referrer';
			probe.onload = () => {
				fullWidths.set( full, probe.naturalWidth );
				measured++;
				render();
			};
			probe.onerror = () => {
				fullWidths.set( full, -1 );
				measured++;
				render();
			};
			probe.src = full;
		}
		const w = fullWidths.get( full );
		return w === null ? null : ( w === -1 ? img.naturalWidth || null : w );
	}

	/** What the reader has of this page: our stored copy where this layout's pages hold what scripts built, else the page as delivered. '': nothing to judge by. */
	async function readerCopy() {
		const r = await ask( 'reads' );
		if ( r.ok && r.data.built ) {
			state.ref = 'stored';
			return r.data.html || '';
		}
		const res = await window.fetch( window.location.href, { credentials: 'include' } );
		return res.ok ? res.text() : Promise.reject( res.status );
	}

	/**
	 * The page as the reader has it, usually as delivered, before its scripts run (owner 2026-11-03, soundiron: the
	 * thumbnail list a slider builds matched nothing on the 63 stored pages, found only at Save). Asked once; null
	 * while it loads or when there is none.
	 */
	let delivered = null;
	let deliveredState = ''; // '' | loading | ok | none | failed
	function deliveredDoc() {
		if ( deliveredState === '' ) {
			deliveredState = 'loading';
			readerCopy()
				.then( ( t ) => {
					if ( ! t ) {
						deliveredState = 'none'; // this layout's pages hold what scripts built, this page has no copy: Test says what they give
						return;
					}
					delivered = new window.DOMParser().parseFromString( t, 'text/html' );
					deliveredState = 'ok';
					SEL.setKnown( delivered ); // selectors from the classes the reader has, never a script's ("slick-slide") where it hasn't them
					if ( ! state.custom && state.picks.length ) {
						recompute();
					}
					render();
				} )
				.catch( () => {
					deliveredState = 'failed';
				} );
		}
		return delivered;
	}

	/** A selector's comma-separated parts (commas inside :not(…) and [attr="…"] stay). */
	function splitSel( sel ) {
		const out = [];
		let depth = 0;
		let cur = '';
		for ( const ch of String( sel ) ) {
			depth += ( ch === '(' || ch === '[' ) ? 1 : ( ch === ')' || ch === ']' ) ? -1 : 0;
			if ( ch === ',' && depth === 0 ) {
				out.push( cur.trim() );
				cur = '';
			} else {
				cur += ch;
			}
		}
		out.push( cur.trim() );
		return out.filter( Boolean );
	}

	/**
	 * Where the delivered page has what $els give (a script-built copy's source): pictures by their full-size address,
	 * else elements of the same tag and text. { sel, what } or null; offered, never applied by itself.
	 */
	function deliveredCopy( raw, k, els ) {
		let found = [];
		if ( k === 'pictures' ) {
			const keys = new Set( SEL.takenFrom( 'pictures', els ).map( ( i ) => SEL.keyOf( SEL.bestSrc( i ) ) ).filter( Boolean ) );
			found = Array.from( raw.querySelectorAll( 'img' ) ).filter( ( i ) => keys.has( SEL.keyOf( SEL.bestSrc( i ) ) ) );
		} else {
			const said = ( e ) => e.textContent.replace( /\s+/g, ' ' ).trim().slice( 0, LIMITS.lineChars );
			topOnly( els ).forEach( ( el ) => {
				const t = said( el );
				const m = t && Array.from( raw.querySelectorAll( el.nodeName.toLowerCase() ) ).find( ( r ) => said( r ) === t );
				if ( m ) {
					found.push( m );
				}
			} );
		}
		if ( ! found.length ) {
			return null;
		}
		const sel = SEL.combine( raw, found );
		const live = sel ? SEL.matchAll( document, sel ) : null;
		if ( ! live || ! live.length ) {
			return null;
		}
		const g = SEL.givesOf( k, live );
		return g.n ? { sel, what: SEL.givesLabel( k, g ) } : null;
	}

	/** allWarnings() once per pick: the outlines are drawn every frame, and finding shop text walks the picks. */
	let warnMemo = { key: null, list: [] };
	function allWarnings() {
		const key = [ state.part, state.sel, state.picks.length, state.matched.length, state.extras.length, state.skipped.size, measured, deliveredState ].join( '|' );
		if ( warnMemo.key !== key ) {
			warnMemo = { key, list: findWarnings() };
		}
		return warnMemo.list;
	}

	function findWarnings() {
		const k = state.part;
		const els = state.matched.length ? state.matched : state.picks;
		if ( ! els.length ) {
			return [];
		}
		const w = MESSAGES.warn;
		const out = [];
		const add = ( key, text, spots, use ) => out.push( { key, text, spots: spots || [], use } );
		const raw = state.sel && ! state.selError && ! /:tab\(/.test( state.sel ) ? deliveredDoc() : null;
		if ( raw ) {
			// Part by part: one script-built part among good ones reads nothing on the stored pages either.
			const parts = splitSel( state.sel );
			const gone = parts.filter( ( p ) => ( SEL.matchAll( raw, p ) || [ null ] ).length === 0 );
			if ( gone.length ) {
				const kept = parts.filter( ( p ) => ! gone.includes( p ) );
				const goneEls = gone.flatMap( ( p ) => SEL.matchAll( document, p ) || [] );
				let use = deliveredCopy( raw, k, goneEls.length ? goneEls : els );
				if ( use && kept.length ) {
					const sel = [ ...kept, use.sel ].join( ', ' );
					const g = SEL.givesOf( k, SEL.matchAll( document, sel ) || [] );
					use = g.n ? { sel, what: SEL.givesLabel( k, g ) } : null;
				}
				const say = state.ref === 'stored' ? MESSAGES.stored : w;
				add( 'built', kept.length ? fill( say.builtPart, { sel: gone.join( ', ' ) } ) : say.built, topOnly( goneEls ).map( ( el ) => ( { el, label: state.ref === 'stored' ? MESSAGES.stored.spot : MESSAGES.spot.built } ) ), use );
			}
		}
		const chrome = els.filter( ( e ) => e.closest( CHROME_AREAS ) );
		if ( chrome.length ) {
			add( 'chrome', w.chrome, chrome.map( ( el ) => ( { el, label: MESSAGES.spot.chrome } ) ) );
		}
		if ( k === 'pictures' ) {
			const imgs = SEL.takenFrom( 'pictures', topOnly( els ) );
			const banner = imgs.filter( ( i ) => {
				const r = i.getBoundingClientRect();
				return r.height > 0 && r.width / r.height >= THRESHOLDS.bannerAspect && r.width >= window.innerWidth * THRESHOLDS.bannerShare;
			} );
			if ( banner.length ) {
				add( 'banner', w.banner, banner.map( ( el ) => ( { el, label: MESSAGES.spot.banner } ) ) );
			}
			const widths = imgs.filter( ( i ) => ! i.closest( SEL.GALLERY ) ).slice( 0, LIMITS.measure ).map( ( el ) => ( { el, w: realWidth( el ) } ) ); // a gallery's thumbnails: the reader takes its full pictures
			const small = widths.filter( ( x ) => x.w !== null && x.w < LIMITS.minPicture );
			if ( small.length ) {
				add( 'small', fill( w.small, { n: small.length, of: imgs.length, w: Math.min( ...small.map( ( x ) => x.w ) ) } ), small.map( ( x ) => ( { el: x.el, label: `${ x.w } px` } ) ) );
			}
			const logos = imgs.filter( ( i ) => LOGO_RE.test( `${ i.currentSrc || i.src } ${ i.alt } ${ i.className }` ) );
			if ( logos.length ) {
				add( 'logo', w.logo, logos.map( ( el ) => ( { el, label: MESSAGES.spot.logo } ) ) );
			}
		}
		if ( k === 'description' ) {
			const shop = shopSpots( els );
			if ( shop.length ) {
				add( 'shop', w.shop, shop );
			}
		}
		if ( k === 'features' ) {
			const lis = topOnly( els ).flatMap( ( e ) => ( e.nodeName === 'LI' ? [ e ] : Array.from( e.querySelectorAll( 'li' ) ) ) );
			const linky = lis.filter( ( li ) => li.querySelector( 'a' ) && li.textContent.trim().length < THRESHOLDS.menuItemChars ).length;
			if ( lis.length && linky / lis.length > THRESHOLDS.menuShare ) {
				add( 'menu', w.menu );
			}
		}
		const g = gives();
		if ( k === 'requirements' && ! g.link && ! REQ_WORDS.test( topOnly( els ).map( SEL.withIcons ).join( ' ' ) ) ) { // badges say it in their alt text
			add( 'noReq', w.noReq );
		}
		if ( g.n && ! g.ok ) {
			add( 'short', fill( w.short, { what: SEL.givesLabel( k, g ) } ) );
		}
		if ( state.extras.length ) {
			add( 'extra', fill( w.extra, { n: state.extras.length } ), state.extras.map( ( el ) => ( { el, label: MESSAGES.spot.extra } ) ) );
		}
		return out;
	}

	/** Show: the warning's next spot scrolled into the middle of the window and flashed. */
	function showSpot( x ) {
		const i = ( state.shown[ x.key ] || 0 ) % x.spots.length;
		const el = x.spots[ i ].el;
		state.shown[ x.key ] = i + 1;
		el.scrollIntoView( { block: 'center', behavior: 'smooth' } );
		state.flash = el;
		window.clearTimeout( state.flashTimer );
		state.flashTimer = window.setTimeout( () => {
			state.flash = null;
		}, LIMITS.flashMs );
		render();
	}

	/** Leave the shop-text lines out on this site: the reader's list of lines to leave out (the extension only). */
	async function leaveOut( x ) {
		const lines = [ ...new Set( x.spots.map( ( sp ) => sp.el.textContent.replace( /\s+/g, ' ' ).trim() ).filter( ( t ) => t.length >= 3 && t.length <= LIMITS.lineChars ) ) ];
		state.busy = true;
		render();
		const r = await ask( 'skip', { lines } );
		state.busy = false;
		if ( r.ok ) {
			x.spots.forEach( ( sp ) => state.skipped.add( lineKey( sp.el ) ) );
			state.note = { text: fill( MESSAGES.leftOut, { n: lines.length } ), type: 'success' };
		} else {
			state.note = { text: r.message, type: 'error' };
		}
		render();
	}

	/* ── Picking ───────────────────────────────────────────────────────── */

	/**
	 * What the picks read. A pick inside a Magento gallery reads the gallery's box (owner 2026-11-04, ilio.com: "Still getting
	 * an error when selecting images"): its script draws what was clicked, so stored pages have only the box, and the reader
	 * takes every picture from the gallery's data. The pick itself stays as clicked.
	 */
	const picksRead = () => ( state.part === 'pictures' ? [ ...new Set( state.picks.map( ( p ) => p.closest( SEL.GALLERY ) || p ) ) ] : state.picks );

	/**
	 * A frame's selector with no more steps than this page needs to tell it from the others. What holds an embed varies
	 * from page to page (ilio.com: div.justify.list around Hardwired's video, on 4 of its 68 pages; div.video-container on 58).
	 */
	function shortFor( sel, picks ) {
		if ( ! sel || /[,:]/.test( sel ) || ! picks.every( ( p ) => p.matches( FRAMES ) ) ) {
			return sel;
		}
		const steps = sel.split( ' ' );
		const n = ( SEL.matchAll( document, sel ) || [] ).length;
		for ( let i = steps.length - 1; i > 0; i-- ) {
			const shorter = steps.slice( i ).join( ' ' );
			if ( ( SEL.matchAll( document, shorter ) || [] ).length === n ) {
				return shorter;
			}
		}
		return sel;
	}

	function recompute() {
		const read = picksRead();
		if ( ! state.custom ) {
			state.sel = read.length ? shortFor( SEL.combine( document, read ), read ) : '';
		}
		const m = state.sel ? SEL.matchAll( document, state.sel ) : [];
		state.matched = m || [];
		state.selError = m === null;
		if ( state.picks.length || state.custom ) {
			state.showSaved = false; // the person's picks take over from the saved pick's outlines
		}
		// What the selector takes beyond the picks: elements beside a pick and of its kind (soundiron's other requirement
		// badges) are read as wanted, "also read"; the rest is a warning (owner 2026-10-31: matches like the pick drawn as
		// red warnings read as the UI overruling the person).
		const extras = state.sel && m ? SEL.extrasOf( document, state.sel, read, state.part ) : [];
		// Bundle contents: the same kind of element in the list's other items or rows is the rest of the list (a product
		// link in each li of its own).
		const item = ( e ) => ( state.part === 'contents' ? e.closest( 'li, tr, dt' ) || e : e );
		const alike = ( el ) => state.picks.some( ( p ) => item( p ).parentElement === item( el ).parentElement && p.nodeName === el.nodeName && p.className === el.className );
		state.alike = extras.filter( alike );
		state.extras = extras.filter( ( el ) => ! alike( el ) );
		state.test = null;
		state.check = null;
		effectSoon();
	}

	/* ── What a pick would do (ExtBridge::map_effect()) ─────────────────── */

	let effectTimer = 0;
	let effectRun = 0;

	/** The pick to look at: the one being made, else the saved one on show. */
	function effectPick() {
		if ( state.sel && ! state.selError ) {
			return { sel: state.sel, mode: mode(), ...scoped() };
		}
		const f = state.showSaved ? savedField( state.part ) : null;
		const own = f && state.layout && state.layout.own && state.layout.own[ state.part ] === f;
		return f ? { sel: f.sel, mode: f.mode || '', ...( own ? { layout: state.layout.i } : {} ) } : null;
	}

	function effectSoon() {
		window.clearTimeout( effectTimer );
		effectRun++;
		state.effect = null;
		const pick = state.greeted && state.map ? effectPick() : null;
		if ( pick ) {
			effectTimer = window.setTimeout( () => lookEffect( pick, effectRun ), LIMITS.effectMs );
		}
	}

	async function lookEffect( pick, run ) {
		for ( let offset = 0; ; ) {
			const r = await ask( 'effect', { field: state.part, offset, ...pick } );
			if ( run !== effectRun ) {
				return; // the pick changed: its own look has begun
			}
			const d = r.ok ? r.data : {};
			state.effect = d.busy ? MESSAGES.effectBusy : d.message || ( typeof d.next === 'number' ? fill( MESSAGES.effectLooking, { n: d.done, t: d.total } ) : null );
			render( true );
			if ( typeof d.next !== 'number' || d.next <= offset ) {
				return;
			}
			offset = d.next;
		}
	}

	function pickable( el ) {
		return SEL.givesOf( state.part, [ el ] ).n > 0 || ( state.part === 'requirements' && SEL.givesOf( 'requirements', [ el ] ).link );
	}

	/** A consent manager's overlay (or something inside its shadow root) under the pointer. */
	function inOverlay( el ) {
		const root = el.getRootNode();
		const top = root !== document && root.host ? root.host : el;
		const named = ( e ) => OVERLAY_RE.test( `${ e.id || '' } ${ typeof e.className === 'string' ? e.className : '' }` );
		if ( [ top, top.parentElement ].some( ( e ) => e && named( e ) ) ) {
			return true;
		}
		// A pop-up drawn over the page (Klaviyo's sign-up form): a fixed box above the pick that names itself as one.
		for ( let e = top.parentElement; e && ! SEL.SKIP_TAGS.includes( e.nodeName ); e = e.parentElement ) {
			if ( named( e ) && window.getComputedStyle( e ).position === 'fixed' ) {
				return true;
			}
		}
		return false;
	}

	function onPick( el, add ) {
		el = SEL.linkFor( state.part, el ); // Manual: the link, not the line around it
		if ( inOverlay( el ) ) {
			flash( MESSAGES.overlay, 'warning' );
			return;
		}
		if ( el.getRootNode() !== document ) {
			flash( MESSAGES.shadow, 'warning' );
			return;
		}
		// A click does what it hits (owner 2026-10-31, "Am I in charge or is the UX?"): one giving nothing is picked too, and said so.
		const nothing = ! pickable( el );
		if ( add ) {
			const i = state.picks.indexOf( el );
			if ( i >= 0 ) {
				state.picks.splice( i, 1 );
			} else if ( state.picks.length < LIMITS.picks ) {
				state.picks.push( el );
			} else {
				flash( fill( MESSAGES.pickLimit, { n: LIMITS.picks } ), 'warning' );
				return;
			}
		} else {
			state.picks = [ el ];
		}
		state.stack = [];
		state.custom = false;
		state.inspecting = 0; // the picks are the person's now; the proposal's box sets them beside it
		recompute();
		render();
		if ( nothing && state.picks.includes( el ) ) {
			flash( fill( MESSAGES.gaveNothing, { part: partName( state.part ) } ), 'warning' );
		}
	}

	function wider() {
		const last = state.picks[ state.picks.length - 1 ];
		const up = last && last.parentElement;
		if ( ! up || SEL.SKIP_TAGS.includes( up.nodeName ) ) {
			return;
		}
		state.stack.push( last );
		state.picks[ state.picks.length - 1 ] = up;
		state.custom = false;
		recompute();
		render();
	}

	function narrower() {
		const back = state.stack.pop();
		if ( ! back ) {
			return;
		}
		state.picks[ state.picks.length - 1 ] = back;
		state.custom = false;
		recompute();
		render();
	}

	const fromPanel = ( e ) => e.composedPath().includes( host );

	const FRAMES = 'iframe, embed, object';
	const frameSheet = new window.CSSStyleSheet();
	frameSheet.replaceSync( `${ FRAMES } { pointer-events: none !important; }` );
	let shielded = false;
	function shieldFrames( on ) {
		if ( on !== shielded ) {
			const others = Array.from( document.adoptedStyleSheets ).filter( ( s ) => s !== frameSheet );
			document.adoptedStyleSheets = on ? [ ...others, frameSheet ] : others;
			shielded = on;
		}
	}

	/** The frame at a point inside $under. Only inside it: a frame taking no clicks leaves what holds it under the pointer, and something drawn over the frame isn't that. */
	function frameAt( under, x, y ) {
		return Array.from( under.querySelectorAll( FRAMES ) ).find( ( f ) => {
			const r = f.getBoundingClientRect();
			return x >= r.left && x <= r.right && y >= r.top && y <= r.bottom;
		} ) || null;
	}

	/** A layer laid over the page that shows nothing itself: positioned, no text, no picture or media in it. */
	const emptyLayer = ( x ) => [ 'absolute', 'fixed' ].includes( window.getComputedStyle( x ).position ) && ! x.textContent.trim() && ! x.querySelector( 'img, picture, video, iframe, canvas, svg' );

	/**
	 * The page element under the pointer. Layers that show nothing pass the pointer to what lies under them: a pop-up's
	 * see-through backdrop (the overlay box itself: Klaviyo's stays a moment after its form closes) and a site's empty
	 * decorative overlays (soundiron's background-overlay over its description). A pop-up's content stays what is under
	 * the pointer (and is refused as a pick: inOverlay()).
	 */
	const pageTarget = ( e ) => {
		let t = e.composedPath()[ 0 ];
		const backdrop = ( x ) => OVERLAY_RE.test( `${ x.id || '' } ${ typeof x.className === 'string' ? x.className : '' }` ) && ! x.textContent.trim();
		if ( t && t.nodeType === 1 && t.getRootNode() === document && ( backdrop( t ) || emptyLayer( t ) ) ) {
			t = document.elementsFromPoint( e.clientX, e.clientY ).find( ( x ) => x !== host && ! backdrop( x ) && ! emptyLayer( x ) && ! inOverlay( x ) ) || t;
		}
		return t && t.nodeType === 1 && ! SEL.SKIP_TAGS.includes( t.nodeName ) ? frameAt( t, e.clientX, e.clientY ) || t : null;
	};

	/**
	 * The control that shows another part of this page, if $el is in one: a tab, an accordion's heading, a link to a spot on
	 * this page. A few words only: a slider's thumbnail can call itself a tab too, and that is a picture to pick.
	 */
	function tabControl( el ) {
		const c = el.closest( '[role="tab"], [aria-controls], a[href^="#"]' );
		const words = c ? c.textContent.trim().length : 0;
		if ( ! words || words > LIMITS.tabChars || c.closest( SEL.GALLERY ) || c.querySelector( 'img, picture, video' ) ) {
			return null; // a label is short: Magento marks a tab's panel as a tab as well
		}
		return c.matches( '[role="tab"], [aria-controls]' ) || document.getElementById( c.getAttribute( 'href' ).slice( 1 ) ) ? c : null;
	}

	function onPointer( e ) {
		if ( ! state.picking || state.browse || fromPanel( e ) ) {
			return;
		}
		const under = pageTarget( e );
		const tab = under && tabControl( under );
		if ( tab ) {
			if ( e.type === 'click' ) {
				state.note = { text: fill( MESSAGES.tabOpened, { name: tab.textContent.trim().slice( 0, LIMITS.previewChars ) } ), type: 'info' };
				render();
			}
			return; // the page's own: it opens the tab
		}
		e.preventDefault();
		e.stopImmediatePropagation();
		if ( e.type === 'click' && e.isTrusted && under ) { // a page's own replayed clicks (tap libraries) are blocked, never taken as picks
			state.note = { text: hint( state.part ), type: 'info' };
			onPick( under, ( state.mode === 'add' ) !== e.shiftKey );
		}
	}

	function onMove( e ) {
		if ( ! state.picking || state.browse || fromPanel( e ) ) {
			state.hover = null;
			tip.hidden = true;
			return;
		}
		const at = pageTarget( e );
		const tab = at && tabControl( at );
		if ( tab ) {
			state.hover = tab;
			tip.textContent = MESSAGES.opensTab;
			tipAt( e.clientX, e.clientY );
			return;
		}
		const t = at ? SEL.linkFor( state.part, at ) : null;
		if ( t === state.hover ) {
			tipAt( e.clientX, e.clientY );
			return;
		}
		state.hover = t;
		if ( t ) {
			const read = ( state.part === 'pictures' && t.closest( SEL.GALLERY ) ) || t; // what a click here would read (picksRead())
			const g = SEL.givesOf( state.part, [ read ] );
			state.tipText = inOverlay( t ) ? MESSAGES.overlay : [ SEL.selectorFor( read ), fill( MESSAGES.gives, { part: partName( state.part ), what: SEL.givesLabel( state.part, g ) } ), ...linkLines( t ) ].join( '\n' );
			tip.textContent = state.tipText;
		}
		tipAt( e.clientX, e.clientY );
	}

	/** Where the hovered link goes (or the links a box holds, for Manual): decoded, and whether the reader takes it. */
	function linkLines( el ) {
		const a = el.closest( 'a[href]' );
		const links = a ? [ a ] : state.part === 'manual' ? Array.from( el.querySelectorAll( 'a[href]' ) ).slice( 0, 4 ) : [];
		const out = [];
		if ( ! a && links.length ) {
			out.push( fill( MESSAGES.linksIn, { n: el.querySelectorAll( 'a[href]' ).length } ) );
		}
		links.forEach( ( l ) => {
			const i = SEL.linkInfo( l );
			if ( i ) {
				out.push( fill( MESSAGES.linkTo, { where: SEL.linkSaid( i ) } ) + ( state.part === 'manual' ? ` ${ i.manual ? MESSAGES.linkManual : MESSAGES.linkNotManual }` : '' ) );
			}
		} );
		return out;
	}

	function onKey( e ) {
		if ( ! state.picking || fromPanel( e ) || e.metaKey || e.ctrlKey || e.altKey || e.target.closest?.( 'input, textarea, select, [contenteditable]' ) ) {
			return;
		}
		const key = e.key.length === 1 ? e.key.toLowerCase() : e.key;
		if ( key === KEYS.browse ) {
			setBrowse( ! state.browse );
		} else if ( state.browse && key !== KEYS.stop ) {
			return; // browsing: the page's keys are the page's
		} else if ( key === KEYS.wider ) {
			wider();
		} else if ( key === KEYS.narrower ) {
			narrower();
		} else if ( key === KEYS.stop ) {
			stopPicking();
		} else {
			return;
		}
		e.preventDefault();
		e.stopImmediatePropagation();
	}

	const hint = ( k ) => fill( state.mode === 'add' ? MESSAGES.hint : MESSAGES.hintReplace, { part: partName( k ) } );

	/** The visible add-or-replace choice for clicks (owner 2026-10-31: default to adding, so nothing is taken away). */
	function modeSwitch() {
		const seg = ( on, text, onclick ) => h( 'button', { type: 'button', class: `seg${ on ? ' on' : '' }`, 'aria-pressed': on ? 'true' : 'false', onclick, text } );
		const opt = ( mode, text ) => seg( ! state.browse && state.mode === mode, text, () => {
			state.mode = mode;
			setBrowse( false );
		} );
		return h( 'div', { class: 'row modes', title: MESSAGES.modeTitle }, h( 'span', { class: 'muted', text: MESSAGES.clicks } ), opt( 'add', MESSAGES.modeAdd ), opt( 'replace', MESSAGES.modeReplace ), seg( state.browse, MESSAGES.modeBrowse, () => setBrowse( true ) ) );
	}

	function setBrowse( on ) {
		state.browse = on;
		state.hover = null;
		tip.hidden = true;
		state.note = { text: on ? MESSAGES.browsing : hint( state.part ), type: 'info' };
		render();
	}

	function startPicking() {
		state.picking = true;
		state.browse = false;
		state.showSaved = false;
		state.note = { text: ( state.away ? fill( MESSAGES.pageAway, { h: state.away } ) + ' ' : '' ) + hint( state.part ), type: 'info' };
		render();
	}

	function stopPicking( keepNote ) {
		state.picking = false;
		state.browse = false;
		if ( keepNote !== true && state.map ) {
			state.note = { text: thisPage(), type: 'info' };
		}
		state.hover = null;
		tip.hidden = true;
		render();
	}

	/* ── Server work (through the window) ──────────────────────────────── */

	async function hello() {
		const r = await ask( 'hello' );
		state.greeted = true;
		if ( ! r.ok ) {
			state.note = { text: r.message || MESSAGES.noAnswer, type: 'error' };
		} else {
			state.map = r.data.map;
			state.products = r.data.products || 0;
			state.away = r.data.away ? r.data.host : '';
			state.pageMode = state.pageMode || !! state.away;
			state.layout = r.data.layout || null;
			state.scope = state.layout && state.layout.pages >= 2 ? 'layout' : 'site';
			state.proposals = r.data.proposals || {};
			state.undo = r.data.undo || {}; // parts whose site pick can be put back (VendorMaps::undoable())
			const waiting = Object.values( state.proposals ).filter( ( p ) => p.status === 'proposed' ).length;
			state.note = state.away ? { text: fill( MESSAGES.pageAway, { h: state.away } ), type: 'info' } : r.data.map ? { text: thisPage() + ( waiting ? fill( MESSAGES.waiting, { n: waiting } ) : '' ), type: 'info' } : { text: MESSAGES.noMap, type: 'warning' };
		}
		if ( r.ok && r.data.map && state.focus ) {
			startPicking(); // opened from the extension panel's row for this part
			showProposal( state.part );
			showSavedPick();
			render();
			return;
		}
		if ( state.picking ) {
			stopPicking( true ); // no map here, or no answer: nothing to save picks to, and the note says why
			return;
		}
		render();
	}

	/* ── Inspecting Claude's proposals (MapProposals) ──────────────────── */

	/**
	 * Claude's proposal for part $k on this page, as picks: what it takes is outlined, said and warned about as any pick
	 * (owner 2026-11-03: inspect the work "like a building inspector would … before approval to continue is granted").
	 * Only into an empty pick, never over the person's own.
	 */
	function showProposal( k, force ) {
		const p = state.proposals[ k ];
		if ( ! p || ! p.sel || ( state.picks.length && ! force ) || ( p.status !== 'proposed' && ! force ) ) {
			return;
		}
		window.clearTimeout( state.editTimer );
		state.sel = p.sel;
		state.custom = true;
		state.picks = topOnly( SEL.matchAll( document, p.sel ) || [] ).slice( 0, LIMITS.picks );
		state.stack = [];
		state.inspecting = p.id;
		recompute();
	}

	const scopeOf = ( p ) => ( p.scope === 'site' ? MESSAGES.propSite : fill( MESSAGES.propLayout, { l: String.fromCharCode( 65 + p.layout ) } ) );

	async function decide( p, decision ) {
		state.busy = true;
		state.note = { text: MESSAGES.deciding, type: 'info', busy: true };
		render();
		const r = await ask( 'proposal', { proposal: p.id, decision } );
		state.busy = false;
		if ( ! r.ok ) {
			state.note = { text: r.message, type: 'error' };
		} else if ( r.data.dry ) {
			state.note = { text: r.data.message, type: 'success' };
		} else {
			state.proposals[ p.field ] = r.data.proposal || p;
			if ( r.data.map ) {
				state.map = r.data.map;
			}
			if ( r.data.layout && state.layout ) {
				state.layout.own = r.data.layout.own || state.layout.own;
			}
			const scope = scopeOf( p );
			state.note = { text: fill( decision === 'approve' ? MESSAGES.approved : MESSAGES.rejected, { part: partName( p.field ), scope, found: p.found, of: p.of } ), type: 'success' };
			state.picks = [];
			state.sel = '';
			state.custom = false;
			state.inspecting = 0;
			recompute();
			if ( BRIDGE.saved ) {
				BRIDGE.saved( r.data );
			}
		}
		render();
	}

	/**
	 * What the proposal reads on this page (owner 2026-11-03: soundiron's manual proposal took the product box's icon link,
	 * out of sight, while the person looked at the Documentation link; nothing said they were the same PDF). From the
	 * live page, else the page as delivered; a manual's links decoded, and set beside the person's own pick.
	 */
	function onThisPage( p ) {
		let els = topOnly( SEL.matchAll( document, p.sel ) || [] );
		let deliveredOnly = false;
		if ( ! els.length && delivered ) {
			els = topOnly( SEL.matchAll( delivered, p.sel ) || [] );
			deliveredOnly = els.length > 0;
		} else if ( ! els.length ) {
			deliveredDoc(); // fetched once; render() comes back here when it arrives
		}
		const out = [];
		if ( p.field === 'manual' ) {
			const seen = new Map();
			SEL.takenFrom( 'manual', els ).forEach( ( a ) => {
				const i = SEL.linkInfo( a );
				if ( i && ! seen.has( i.url ) ) {
					seen.set( i.url, i );
				}
			} );
			if ( ! seen.size ) {
				return [ h( 'div', { class: 'muted', text: MESSAGES.inspectHereNone } ) ];
			}
			out.push( h( 'div', { class: 'muted', text: MESSAGES.inspectHere } ), h( 'ul', { class: 'samples' }, [ ...seen.values() ].slice( 0, 4 ).map( ( i ) => h( 'li', {}, h( 'b', { text: i.words || MESSAGES.iconLink } ), ` ${ fill( MESSAGES.linkTo, { where: SEL.linkSaid( i ) } ) }` ) ) ) );
			if ( state.inspecting !== p.id && state.part === 'manual' && state.picks.length ) {
				const mine = new Set( SEL.takenFrom( 'manual', state.picks ).map( ( a ) => ( SEL.linkInfo( a ) || {} ).url ).filter( Boolean ) );
				if ( mine.size ) {
					out.push( h( 'div', { text: [ ...mine ].some( ( u ) => seen.has( u ) ) ? MESSAGES.inspectSameFile : MESSAGES.inspectOtherFile } ) );
				}
			}
		} else {
			const g = SEL.givesOf( p.field, els );
			out.push( h( 'div', { class: 'muted', text: g.n ? `${ MESSAGES.inspectHere } ${ SEL.givesLabel( p.field, g ) }` : MESSAGES.inspectHereNone } ) );
		}
		if ( deliveredOnly ) {
			out.push( h( 'div', { class: 'muted', text: state.ref === 'stored' ? MESSAGES.stored.inspect : MESSAGES.inspectDelivered } ) );
		}
		return out;
	}

	/** The proposal's box: its element, its reach, Claude's note, what it reads on three products, the decision. */
	function inspectBox( p ) {
		const scope = scopeOf( p );
		const open = p.status === 'proposed';
		return h( 'div', { class: 'inspect' },
			h( 'b', { text: fill( MESSAGES.inspectHead, { part: partName( p.field ), scope } ) } ),
			open ? null : h( 'span', { class: 'muted', text: MESSAGES.proposalDone[ p.status ] || p.status } ),
			h( 'code', { text: p.sel } ),
			h( 'div', { class: 'muted', text: fill( MESSAGES.inspectFound, p ) } ),
			p.note ? h( 'div', { text: fill( MESSAGES.inspectNote, { n: p.note } ) } ) : null,
			...onThisPage( p ),
			( p.samples || [] ).length ? h( 'div', { class: 'muted', text: MESSAGES.inspectReads } ) : null,
			( p.samples || [] ).length ? h( 'ul', { class: 'samples' }, p.samples.map( ( x ) => h( 'li', {}, h( 'b', { text: x.title } ), ` · ${ x.n ? ( x.short || x.detail ) : MESSAGES.inspectNothing }` ) ) ) : null,
			h( 'div', { class: 'row' },
				h( 'button', { type: 'button', class: 'btn quiet', disabled: state.busy || undefined, onclick: () => {
					showProposal( p.field, true );
					render();
					const first = state.picks.find( ( el ) => el.getClientRects().length );
					if ( first ) {
						first.scrollIntoView( { block: 'center', behavior: 'smooth' } );
					}
				}, text: MESSAGES.inspectShow } ),
				open ? h( 'button', { type: 'button', class: 'btn primary', disabled: state.busy || undefined, onclick: () => decide( p, 'approve' ), text: MESSAGES.approve } ) : null,
				open ? h( 'button', { type: 'button', class: 'btn quiet', disabled: state.busy || undefined, onclick: () => decide( p, 'reject' ), text: MESSAGES.reject } ) : null ) );
	}

	function thisPage() {
		const n = state.products;
		const lay = state.layout ? ` ${ fill( MESSAGES.layoutPage, { l: state.layout.letter, n: state.layout.pages } ) }` : '';
		return fill( MESSAGES.thisPage[ n ] || MESSAGES.thisPage.n, { n } ) + lay;
	}

	/** Test and Save for this page's layout only, or the whole site. */
	function scoped() {
		return state.layout && state.scope === 'layout' ? { layout: state.layout.i } : {};
	}

	/** A saved pick taken away, for the layout (its own) or the site: on a second click, since it may have taken care to make. */
	async function clearSaved( b ) {
		const k = state.part;
		const own = state.layout && state.layout.own && state.layout.own[ k ] && state.layout.own[ k ].sel;
		if ( ! b.dataset.armed ) {
			b.dataset.armed = '1';
			b.textContent = own ? MESSAGES.clearLayoutSure : MESSAGES.clearSure;
			return;
		}
		b.disabled = true;
		const r = await ask( 'clear', { field: k, ...( own ? { layout: state.layout.i } : {} ) } );
		if ( ! r.ok ) {
			state.note = { text: r.message || MESSAGES.noAnswer, type: 'error' };
			render();
			return;
		}
		state.showSaved = false;
		state.saved = [];
		state.note = { text: `${ r.data.message || fill( MESSAGES.cleared, { part: partName( k ) } ) } ${ r.data.said || '' }`.trim(), type: 'success' };
		if ( ! r.data.dry ) {
			await hello();
			if ( BRIDGE.saved ) {
				BRIDGE.saved( r.data );
			}
		}
		render();
	}

	/** The site's pick for this part as it was before its last change (VendorMaps::undo_part()). */
	async function undoPart( b ) {
		const k = state.part;
		b.disabled = true;
		const r = await ask( 'undo', { field: k } );
		if ( ! r.ok ) {
			state.note = { text: r.message || MESSAGES.noAnswer, type: 'error' };
			render();
			return;
		}
		state.showSaved = false;
		state.saved = [];
		await hello();
		state.note = { text: `${ fill( MESSAGES.undone, { part: partName( k ) } ) } ${ r.data.said || '' }`.trim(), type: 'success' }; // after hello(), which sets its own
		if ( BRIDGE.saved ) {
			BRIDGE.saved( r.data );
		}
		render();
	}

	function mode() {
		const k = state.part;
		if ( k !== 'requirements' ) {
			return '';
		}
		if ( state.pageMode ) {
			return 'page';
		}
		return gives().link ? 'follow' : 'text';
	}

	async function test() {
		state.busy = true;
		state.note = { text: MESSAGES.testing, type: 'info', busy: true };
		render();
		const r = await ask( 'test', { field: state.part, sel: state.sel, mode: mode(), html: pageHtml(), ...scoped() } );
		state.busy = false;
		if ( r.ok ) {
			state.test = r.data;
			state.note = { text: fill( scoped().layout === undefined ? MESSAGES.tested : MESSAGES.testedLayout, { ...r.data, l: state.layout && state.layout.letter } ), type: r.data.found ? 'success' : 'warning' };
		} else {
			state.note = { text: r.message, type: 'error' };
		}
		render();
		if ( r.ok ) {
			checkSite();
		}
	}

	/** The tested pick across the site's stored pages (MapCheck: repeated pictures, other products' pictures, collection pages, outliers). */
	async function checkSite() {
		const sel = state.sel;
		state.check = { busy: true, lines: [] };
		render();
		const r = await ask( 'check', { field: state.part, sel, mode: mode() } );
		if ( state.sel !== sel || ! state.test ) {
			return; // the pick changed while the site was checked
		}
		state.check = r.ok ? ( r.data.busy ? { lines: [ { type: 'info', text: MESSAGES.checkBusy, examples: [] } ] } : r.data ) : null;
		render();
	}

	async function save() {
		const warn = warnings();
		state.busy = true;
		state.note = { text: MESSAGES.saving, type: 'info', busy: true };
		render();
		const forLayout = scoped().layout !== undefined;
		const r = await ask( 'save', { field: state.part, sel: state.sel, mode: mode(), html: pageHtml(), warn: warn.map( ( x ) => x.text ).join( ' ' ), where: `Picked on the live page ${ location.pathname }`, ...scoped() } );
		state.busy = false;
		if ( r.ok ) {
			state.map = state.map || {};
			state.map.fields = r.data.fields;
			state.map.status = r.data.status;
			const f = r.data.field || {};
			if ( forLayout ) {
				state.layout.own = Object.assign( {}, state.layout.own, { [ state.part ]: f } );
			}
			const said = typeof r.data.said === 'string' ? r.data.said : null; // a contents list: what the store's bundle review did with it
			state.note = { text: fill( said !== null ? MESSAGES.savedContents : ( forLayout ? MESSAGES.savedLayout : MESSAGES.saved ), { part: partName( state.part ), found: f.found, of: f.of, l: state.layout && state.layout.letter, said: said || '' } ).trim(), type: warn.length ? 'warning' : 'success' };
			if ( BRIDGE.saved ) {
				BRIDGE.saved( r.data );
			}
			state.picks = [];
			state.sel = '';
			state.custom = false;
			recompute();
			stopPicking( true );
			showSavedPick();
		} else {
			state.note = { text: r.message, type: 'error' };
		}
		render();
	}

	/* ── Drawing ───────────────────────────────────────────────────────── */

	const host = h( 'div', { id: 'mmpt-live-mapper' } );
	const root = host.attachShadow( { mode: 'open' } );
	try {
		const sheet = new CSSStyleSheet();
		sheet.replaceSync( CSS );
		root.adoptedStyleSheets = [ sheet ]; // not subject to the page's style-src policy
	} catch ( e ) {
		root.append( h( 'style', { text: CSS } ) );
	}
	const layer = h( 'div', { class: 'layer' } );
	const tip = h( 'div', { class: 'tip', hidden: true } );
	const panel = h( 'aside', { class: 'panel', 'aria-label': MESSAGES.title } );
	layer.append( tip );
	root.append( layer, panel );
	document.documentElement.append( host );

	const pool = [];
	function boxes() {
		const list = [];
		const k = state.part;
		if ( state.showSaved ) {
			state.saved.forEach( ( el ) => list.push( [ el, 'saved', COLORS[ k ], MESSAGES.spot.saved ] ) );
		}
		state.alike.forEach( ( el ) => list.push( [ el, 'extra', COLORS[ k ], MESSAGES.spot.alike ] ) );
		state.extras.forEach( ( el ) => list.push( [ el, 'extra', COLORS.extra, MESSAGES.spot.extra ] ) );
		if ( state.sel || state.picks.length ) { // what each warning is about, labelled on the page
			warnings().filter( ( x ) => x.key !== 'extra' ).forEach( ( x ) => x.spots.forEach( ( sp ) => list.push( [ sp.el, 'flag', COLORS.extra, sp.label ] ) ) );
		}
		if ( state.flash ) {
			list.unshift( [ state.flash, 'flash', COLORS.extra, '' ] );
		}
		( state.custom ? state.matched : [ ...new Set( [ ...picksRead(), ...state.picks ] ) ] ).forEach( ( el ) => list.push( [ el, 'pick', COLORS[ k ] ] ) );
		if ( state.picking && state.hover ) {
			list.push( [ state.hover, 'hover', COLORS.hover ] );
		}
		if ( state.peek && state.peek.li.isConnected ) { // a pick hovered in the panel's list
			list.push( [ state.peek.el, 'hover', COLORS.hover ] );
		}
		return list.slice( 0, LIMITS.boxes );
	}

	/**
	 * The ancestors that clip an element (overflow other than visible), found once per element. A slider keeps its
	 * other slides in a strip beside the one shown; their outlines were drawn over the header (owner 2026-11-03).
	 */
	const clippers = new WeakMap();
	function clipsOf( el ) {
		if ( ! clippers.has( el ) ) {
			const list = [];
			for ( let a = el.parentElement; a && a !== document.body && a !== document.documentElement; a = a.parentElement ) {
				const cs = window.getComputedStyle( a );
				if ( cs.overflowX !== 'visible' || cs.overflowY !== 'visible' ) {
					list.push( a );
				}
			}
			clippers.set( el, list );
		}
		return clippers.get( el );
	}

	/** The part of $el that shows: its box within its clipping ancestors, or null when nothing of it shows. */
	function shownRect( el ) {
		if ( typeof el.checkVisibility === 'function' && ! el.checkVisibility( { opacityProperty: true, visibilityProperty: true } ) ) {
			return null;
		}
		const r = el.getBoundingClientRect();
		let [ l, t, rr, b ] = [ r.left, r.top, r.right, r.bottom ];
		for ( const a of clipsOf( el ) ) {
			const c = a.getBoundingClientRect();
			[ l, t, rr, b ] = [ Math.max( l, c.left ), Math.max( t, c.top ), Math.min( rr, c.right ), Math.min( b, c.bottom ) ];
		}
		return rr - l < 1 || b - t < 1 ? null : { left: l, top: t, width: rr - l, height: b - t };
	}

	function frame() {
		if ( ! host.isConnected ) {
			return;
		}
		const list = boxes();
		while ( pool.length < list.length ) {
			const b = h( 'div', { class: 'box' } );
			pool.push( b );
			layer.append( b );
		}
		pool.forEach( ( b, i ) => {
			const item = list[ i ];
			const r = item && item[ 0 ].isConnected ? shownRect( item[ 0 ] ) : null;
			if ( ! r || ( ! r.width && ! r.height ) ) {
				b.hidden = true;
				return;
			}
			b.hidden = false;
			b.className = `box ${ item[ 1 ] }`;
			b.dataset.label = item[ 3 ] || '';
			b.style.setProperty( '--x', `${ r.left }px` );
			b.style.setProperty( '--y', `${ r.top }px` );
			b.style.setProperty( '--w', `${ r.width }px` );
			b.style.setProperty( '--h', `${ r.height }px` );
			b.style.setProperty( '--c', item[ 2 ] );
		} );
		window.requestAnimationFrame( frame );
	}

	function tipAt( x, y ) {
		tip.hidden = ! state.picking || ! state.hover;
		tip.style.setProperty( '--x', `${ Math.min( x + 14, window.innerWidth - 390 ) }px` );
		tip.style.setProperty( '--y', `${ Math.min( y + 16, window.innerHeight - 60 ) }px` );
	}

	let noteTimer = 0;
	function flash( text, type ) {
		const keep = state.note;
		const shown = { text, type };
		state.note = shown;
		render();
		window.clearTimeout( noteTimer );
		noteTimer = window.setTimeout( () => {
			if ( state.note === shown ) { // a test or save started since keeps its own note
				state.note = keep;
				render();
			}
		}, 2500 );
	}

	/** The pick that reads part $k here: this layout's own, else the site's. */
	function savedField( k ) {
		const own = state.layout && state.layout.own ? state.layout.own[ k ] : null;
		if ( own && own.sel ) {
			return own;
		}
		const site = state.map && state.map.fields ? state.map.fields[ k ] : null;
		return state.away && site && site.mode !== 'page' ? null : site; // on a page kept for the site, a pick for its product pages isn't saved here, and nothing here may clear it
	}

	/** The tab $el is hidden in, opened: a saved pick in a closed tab can't be seen or outlined. */
	function openTabOf( el ) {
		if ( typeof el.checkVisibility !== 'function' || el.checkVisibility() ) {
			return;
		}
		for ( let n = el; n && n !== document.body; n = n.parentElement ) {
			const id = n.id ? window.CSS.escape( n.id ) : ''; // window's: CSS here is this file's styles
			const c = id ? document.querySelector( `a[href="#${ id }"], [aria-controls="${ id }"]` ) : null;
			if ( c && tabControl( c ) ) {
				c.click();
				return;
			}
		}
	}

	/** The part's saved pick outlined on the page, brought into view when none of it is. Not over picks being made. */
	function showSavedPick() {
		const f = savedField( state.part );
		state.showSaved = !! f && ! state.picks.length && ! state.custom;
		state.saved = state.showSaved ? topOnly( SEL.matchAll( document, f.sel ) || [] ) : [];
		if ( state.saved.length ) {
			openTabOf( state.saved[ 0 ] );
		}
		effectSoon();
		const shown = ( el ) => {
			const r = el.getBoundingClientRect();
			return r.bottom > 0 && r.top < window.innerHeight;
		};
		if ( state.saved.length && ! state.saved.some( shown ) ) {
			state.saved[ 0 ].scrollIntoView( { block: 'center' } );
		}
	}

	function partRow( k ) {
		const f = savedField( k );
		const own = state.layout && state.layout.own ? state.layout.own[ k ] : null;
		const info = own && own.how === 'pick'
			? fill( MESSAGES.layoutOwn, { l: state.layout.letter, found: own.found, of: own.of } )
			: f
				? `${ f.via === 'live' ? `${ MESSAGES.savedBy.person } ${ MESSAGES.live }` : MESSAGES.savedBy[ f.source ] || MESSAGES.savedBy.auto } · ${ fill( MESSAGES.works, f ) }`
				: state.focus ? ( state.picks.length ? '' : MESSAGES.notPicked ) : MESSAGES.notMapped;
		const row = h( state.focus ? 'div' : 'button', state.focus ? { class: 'part on lone' } : { type: 'button', class: `part${ state.part === k ? ' on' : '' }`, 'aria-pressed': state.part === k ? 'true' : 'false', onclick: () => choose( k ) },
			h( 'span', { class: 'dot' } ), h( 'span', { class: 'name', text: partName( k ) } ),
			info ? h( 'span', { class: 'info', text: info } ) : null,
			state.proposals[ k ] ? h( 'span', { class: 'prop', text: state.proposals[ k ].status === 'proposed' ? fill( MESSAGES.proposedInfo, state.proposals[ k ] ) : MESSAGES.proposalDone[ state.proposals[ k ].status ] || '' } ) : null,
			f && f.warn ? h( 'span', { class: 'warned', text: fill( MESSAGES.warnSaved, { w: f.warn } ) } ) : null );
		row.style.setProperty( '--c', COLORS[ k ] );
		return row;
	}

	function choose( k ) {
		if ( k === state.part ) {
			return;
		}
		state.part = k;
		state.picks = [];
		state.stack = [];
		state.sel = '';
		state.custom = false;
		state.showSaved = false;
		state.pageMode = !! state.away;
		state.inspecting = 0;
		recompute();
		showProposal( k );
		showSavedPick();
		if ( state.picking ) {
			state.note = { text: hint( k ), type: 'info' };
		}
		render();
	}

	function toggleSaved() {
		const f = savedField( state.part );
		state.showSaved = ! state.showSaved && !! f;
		state.saved = state.showSaved ? topOnly( SEL.matchAll( document, f.sel ) || [] ) : [];
		render();
	}

	function pickItem( el, i ) {
		const d = SEL.describe( state.part, el );
		const li = h( 'li', {},
			h( 'div', { class: 'top' }, h( 'code', { text: d.tag } ), h( 'button', { type: 'button', class: 'icon', 'aria-label': 'Remove', text: '×', onclick: () => {
				state.picks.splice( i, 1 );
				state.custom = false;
				recompute();
				render();
			} } ) ),
			d.pictures && d.pictures.length ? h( 'div', { class: 'thumbs' }, d.pictures.slice( 0, LIMITS.thumbs ).map( ( p ) => h( 'img', { src: p.src, alt: '', title: `${ p.w } × ${ p.h }` } ) ) ) : null,
			d.items && d.items.length ? h( 'span', { class: 'muted', text: d.items.join( ' · ' ).slice( 0, LIMITS.previewChars ) } ) : null,
			d.links && d.links.length ? h( 'span', { class: 'muted', text: d.links.map( ( a ) => `${ a.text || MESSAGES.iconLink } ${ fill( MESSAGES.linkTo, { where: a.info ? SEL.linkSaid( a.info ) : a.href } ) }` ).join( ' · ' ) } ) : null,
			d.videos && d.videos.length ? h( 'span', { class: 'muted', text: d.videos.join( ' · ' ).slice( 0, LIMITS.previewChars ) } ) : null,
			! d.pictures && ! d.items && ! d.links && ! ( d.videos && d.videos.length ) ? h( 'span', { class: 'muted', text: d.text.slice( 0, LIMITS.previewChars ) } ) : null );
		li.addEventListener( 'mouseenter', () => {
			state.peek = { el, li };
		} );
		li.addEventListener( 'mouseleave', () => {
			state.peek = null;
		} );
		return li;
	}

	function workArea() {
		const k = state.part;
		const f = savedField( k );
		const box = h( 'div', { class: 'work' } );
		box.append( h( 'div', { class: 'row' },
			state.picking
				? h( 'button', { type: 'button', class: 'btn quiet', onclick: stopPicking, text: MESSAGES.stop } )
				: h( 'button', { type: 'button', class: 'btn primary', disabled: ! state.map || undefined, onclick: startPicking, text: f ? MESSAGES.pickAgain : MESSAGES.pick } ),
			f ? h( 'button', { type: 'button', class: 'btn quiet', onclick: toggleSaved, text: state.showSaved ? MESSAGES.hideSaved : MESSAGES.showSaved } ) : null,
			f && f.sel ? h( 'button', { type: 'button', class: 'btn quiet', onclick: ( e ) => clearSaved( e.currentTarget ), text: MESSAGES.clearPick } ) : null,
			state.undo && state.undo[ k ] && ! state.picking ? h( 'button', { type: 'button', class: 'btn quiet', title: fill( MESSAGES.undoTitle, { at: state.undo[ k ] } ), onclick: ( e ) => undoPart( e.currentTarget ), text: MESSAGES.undoPick } ) : null ) );
		if ( state.picking ) {
			box.append( modeSwitch() );
		}
		if ( f ) {
			const own = state.layout && state.layout.own && state.layout.own[ k ] === f;
			box.append( h( 'div', { class: 'savedbox' },
				h( 'b', { text: own ? fill( MESSAGES.savedForLayout, { l: state.layout.letter } ) : MESSAGES.savedForSite } ),
				h( 'code', { text: f.sel } ),
				state.showSaved ? h( 'div', { class: 'muted', text: state.saved.length ? fill( MESSAGES.gives, { part: partName( k ), what: SEL.givesLabel( k, SEL.givesOf( k, state.saved ) ) } ) : MESSAGES.savedNone } ) : null ) );
		}
		if ( state.proposals[ k ] ) {
			box.append( inspectBox( state.proposals[ k ] ) );
		}
		const said = state.effect ? h( 'div', { class: 'effect', role: 'status', text: state.effect } ) : null;
		if ( ! state.picks.length && ! state.custom ) {
			if ( said ) {
				box.append( said );
			}
			return box;
		}
		box.append( h( 'div', { class: 'muted', text: fill( MESSAGES.picks, { n: state.picks.length } ) } ), h( 'ul', { class: 'picklist' }, state.picks.map( pickItem ) ) );
		const input = h( 'input', { class: 'sel', type: 'text', value: state.sel, 'aria-label': MESSAGES.selector, spellcheck: 'false' } );
		input.addEventListener( 'input', () => {
			window.clearTimeout( state.editTimer );
			state.editTimer = window.setTimeout( () => {
				state.sel = input.value.trim();
				state.custom = true;
				recompute();
				render( true );
			}, LIMITS.editMs );
		} );
		box.append( h( 'div', { class: 'muted', text: MESSAGES.selector } ), input );
		if ( state.selError ) {
			box.append( h( 'div', { class: 'note error', text: MESSAGES.badSelector } ) );
		}
		const g = gives();
		box.append( h( 'div', { text: fill( MESSAGES.gives, { part: partName( k ), what: SEL.givesLabel( k, g ) } ) } ) );
		if ( k === 'description' ) {
			box.append( h( 'div', { class: 'muted', text: MESSAGES.plainText } ) );
		}
		if ( k === 'pictures' ) {
			const imgs = SEL.takenFrom( 'pictures', topOnly( state.matched.length ? state.matched : state.picks ) ).slice( 0, LIMITS.measure );
			const widths = imgs.map( realWidth );
			const distinct = new Set( imgs.map( ( i ) => SEL.keyOf( SEL.bestSrc( i ) ) ) ).size;
			const widest = Math.max( 0, ...widths.filter( ( x ) => x ) );
			box.append( h( 'div', { class: 'muted', text: widths.includes( null ) ? MESSAGES.measuring : fill( MESSAGES.fullSizes, { n: distinct, w: widest ? fill( MESSAGES.fullWidest, { w: widest } ) : '' } ) } ) );
			if ( ! state.custom && state.picks.some( ( p ) => p.closest( SEL.GALLERY ) ) ) {
				box.append( h( 'div', { class: 'muted', text: MESSAGES.wholeGallery } ) );
			}
		}
		if ( state.alike.length ) {
			box.append( h( 'div', { class: 'muted', text: fill( MESSAGES.alikeN, { n: state.alike.length } ) } ) );
		}
		if ( state.extras.length ) {
			box.append( h( 'div', { class: 'muted', text: fill( MESSAGES.takesExtra, { n: state.extras.length } ) } ) );
		}
		if ( state.layout && state.layout.pages >= 2 ) {
			const radio = ( value, text ) => h( 'label', { class: 'check' }, h( 'input', { type: 'radio', name: 'mmpt-lm-scope', value, checked: state.scope === value || undefined, onchange: () => {
				state.scope = value;
				state.test = null;
				effectSoon();
				render();
			} } ), text );
			box.append( h( 'div', { class: 'muted', text: MESSAGES.scope } ),
				radio( 'layout', fill( MESSAGES.scopeLayout, { l: state.layout.letter, n: state.layout.pages } ) ),
				radio( 'site', MESSAGES.scopeSite ) );
		}
		if ( k === 'requirements' && state.away ) {
			box.append( h( 'div', { class: 'muted', text: fill( MESSAGES.pageAway, { h: state.away } ) } ) );
		} else if ( k === 'requirements' ) {
			box.append( h( 'label', { class: 'check' }, h( 'input', { type: 'checkbox', checked: state.pageMode || undefined, onchange: ( e ) => {
				state.pageMode = e.target.checked;
				state.test = null;
				render();
			} } ), MESSAGES.pageMode ) );
		}
		box.append( h( 'div', { class: 'row' },
			h( 'button', { type: 'button', class: 'btn quiet', disabled: ! state.picks.length || state.busy || undefined, onclick: wider, text: MESSAGES.wider } ),
			h( 'button', { type: 'button', class: 'btn quiet', disabled: ! state.stack.length || state.busy || undefined, onclick: narrower, text: MESSAGES.narrower } ) ) );
		const warn = warnings();
		if ( warn.length ) {
			box.append( h( 'div', { class: 'warnbox', role: 'note' }, h( 'b', { text: fill( MESSAGES.warnHead, { part: partName( k ) } ) } ), h( 'ul', {}, warn.map( ( x ) => h( 'li', {},
				h( 'span', { text: x.text } ),
				h( 'span', { class: 'wa' },
					x.spots.length ? h( 'button', { type: 'button', onclick: () => showSpot( x ), text: x.spots.length > 1 ? fill( MESSAGES.showN, { i: ( ( state.shown[ x.key ] || 0 ) % x.spots.length ) + 1, n: x.spots.length } ) : MESSAGES.show } ) : null,
					x.key === 'shop' && BRIDGE.ask ? h( 'button', { type: 'button', title: MESSAGES.leaveOutTitle, disabled: state.busy || undefined, onclick: () => leaveOut( x ), text: MESSAGES.leaveOut } ) : null,
					x.use ? h( 'button', { type: 'button', class: 'use', title: fill( state.ref === 'stored' ? MESSAGES.stored.useTitle : MESSAGES.useDeliveredTitle, { sel: x.use.sel } ), onclick: () => {
						window.clearTimeout( state.editTimer ); // a selector still being typed doesn't come back over it
						state.sel = x.use.sel;
						state.custom = true;
						// What it takes becomes the picks: the old picks would make its own slides "unlike your picks".
						state.picks = topOnly( SEL.matchAll( document, x.use.sel ) || [] ).slice( 0, LIMITS.picks );
						state.stack = [];
						recompute();
						render();
					}, text: fill( state.ref === 'stored' ? MESSAGES.stored.use : MESSAGES.useDelivered, { what: x.use.what } ) } ) : null,
					h( 'button', { type: 'button', title: MESSAGES.dismissTitle, onclick: () => {
						state.dismissed.add( x.key );
						render();
					}, text: MESSAGES.dismiss } ) ) ) ) ) ) );
		}
		if ( state.test && state.test.sample && state.test.sample.detail ) {
			box.append( h( 'div', { class: 'muted', text: fill( MESSAGES.sample, { detail: state.test.sample.detail } ) } ) );
		}
		if ( state.test && state.check ) {
			box.append( state.check.busy
				? h( 'div', { class: 'note busy', text: MESSAGES.checking } )
				: h( 'div', { class: 'checks', 'aria-label': MESSAGES.checkHead },
					h( 'div', { class: 'muted', text: MESSAGES.checkHead } ),
					...( state.check.lines || [] ).map( ( l ) => h( 'div', { class: `note ${ l.type || 'info' }` },
						h( 'div', { text: l.text } ),
						( l.examples || [] ).length ? h( 'div', { class: 'ex' }, ...l.examples.map( ( x ) => h( 'a', { href: x.url, target: '_blank', rel: 'noopener', title: x.url, text: x.title || x.url.replace( /^https?:\/\/[^/]+/, '' ) || x.url } ) ) ) : null ) ) ) );
		}
		const ready = state.map && state.sel && ! state.selError && state.matched.length && ! state.busy; // picks can start before the server has answered
		if ( said ) {
			box.append( said );
		}
		box.append( h( 'div', { class: 'row' },
			h( 'button', { type: 'button', class: 'btn', disabled: ! ready || undefined, onclick: test, text: MESSAGES.test } ),
			h( 'button', { type: 'button', class: `btn ${ warn.length ? 'warn' : 'primary' }`, disabled: ! ready || undefined, onclick: save, text: ( warn.length ? MESSAGES.saveWarn : fill( MESSAGES.save, { part: partName( k ) } ) ) + ( scoped().layout !== undefined ? ` · ${ fill( MESSAGES.layoutShort, { l: state.layout.letter } ) }` : '' ) } ) ) );
		return box;
	}

	function render( keepFocus ) {
		shieldFrames( state.picking && ! state.browse );
		const active = keepFocus ? root.activeElement : null;
		const caret = active && active.selectionStart;
		const m = state.map;
		const status = m ? MESSAGES.status[ m.status ] || m.status : '';
		const working = m || ( state.focus && ! state.greeted ); // a row's part shows while the server answers
		panel.classList.toggle( 'left', state.left );
		const note = state.note.busy
			? h( 'div', { class: `note ${ state.note.type } busy`, role: 'status' }, h( 'span', { class: 'spin', 'aria-hidden': 'true' } ), h( 'span', { text: state.note.text } ) )
			: h( 'div', { class: `note ${ state.note.type }`, role: 'status', text: state.note.text } );
		panel.replaceChildren(
			h( 'div', { class: 'head' },
				state.focus ? h( 'button', { type: 'button', class: 'icon back', title: MESSAGES.backTitle, 'aria-label': MESSAGES.backTitle, text: MESSAGES.back, onclick: teardown } ) : null,
				h( 'b', {}, `${ MESSAGES.title } `, h( 'span', { class: 'host', text: `· ${ state.host }` } ) ),
				status ? h( 'span', { class: 'badge', text: status } ) : null,
				h( 'button', { type: 'button', class: 'icon', title: MESSAGES.move, 'aria-label': MESSAGES.move, text: '⇄', onclick: () => {
					state.left = ! state.left;
					render();
				} } ),
				state.focus ? null : h( 'button', { type: 'button', class: 'icon', title: MESSAGES.close, 'aria-label': MESSAGES.close, text: '×', onclick: teardown } ) ),
			h( 'div', { class: 'body' },
				note,
				working ? h( 'div', { class: 'parts', role: 'group', 'aria-label': 'Parts' }, ( state.focus ? [ state.part ] : PARTS ).map( partRow ) ) : null,
				working ? workArea() : null,
				working && state.focus && BRIDGE.single && MESSAGES.single[ state.part ] ? h( 'button', { type: 'button', class: 'linkbtn', text: `${ MESSAGES.single[ state.part ] } ›`, onclick: () => BRIDGE.single( state.part ) } ) : null,
				h( 'div', { class: 'foot' }, h( 'span', { class: 'muted', text: m ? thisPage() : '' } ), state.focus ? null : h( 'a', { href: `${ BRIDGE.origin }${ ADMIN_PATH }${ m ? `&wb_site=${ encodeURIComponent( state.host ) }` : '' }`, target: '_blank', rel: 'noopener', text: MESSAGES.openMaps } ) ) ) );
		if ( active && active.classList && active.classList.contains( 'sel' ) ) {
			const again = panel.querySelector( 'input.sel' );
			if ( again ) {
				again.focus();
				if ( typeof caret === 'number' ) {
					again.setSelectionRange( caret, caret );
				}
			}
		}
	}

	const LISTEN = [ [ 'pointerdown', onPointer ], [ 'mousedown', onPointer ], [ 'pointerup', onPointer ], [ 'mouseup', onPointer ], [ 'click', onPointer ], [ 'mousemove', onMove ], [ 'keydown', onKey ] ];
	LISTEN.forEach( ( [ type, fn ] ) => window.addEventListener( type, fn, true ) );

	function teardown() {
		effectRun++;
		window.clearTimeout( effectTimer );
		shieldFrames( false );
		LISTEN.forEach( ( [ type, fn ] ) => window.removeEventListener( type, fn, true ) );
		host.remove();
		delete window.mmptLiveMapper;
		if ( BRIDGE.closed ) {
			BRIDGE.closed();
		}
	}

	window.mmptLiveMapper = {
		close: teardown,
		/** Asked again while open: part is the panel row clicked (nothing: Pick a part on this page, the list). */
		show( part ) {
			if ( ! host.isConnected ) {
				document.documentElement.append( host );
			}
			panel.hidden = false;
			state.focus = PARTS.includes( part );
			if ( state.focus ) {
				choose( part );
			}
			if ( state.focus && state.map && ! state.picking ) {
				startPicking();
			}
			render();
		},
	};

	if ( state.focus ) {
		state.note = { text: hint( state.part ), type: 'info' };
	}
	render();
	window.requestAnimationFrame( frame );
	hello();
}() );
