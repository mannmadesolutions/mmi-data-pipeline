/**
 * Export tab — Site export section (MMI_Site_Export).
 * Starts packages, polls while one is building, copies signed links and
 * deletes packages (a second click within ARM_MS confirms).
 */
( function () {
	'use strict';

	const CONFIG = window.mmiSiteExport || {};
	const SELECTORS = {
		section: '#mmi-site-export',
		rows: '#mmi-site-export-rows',
		target: '#mmi-site-export-target',
		days: '#mmi-site-export-days',
		start: '#mmi-site-export-start',
		notice: '#mmi-site-export-notice',
		link: '.mmi-site-export-link',
		remove: '.mmi-site-export-delete',
	};
	const POLL_MS = CONFIG.pollMs || 5000;
	const ARM_MS = CONFIG.armMs || 4000;

	const section = document.querySelector( SELECTORS.section );
	if ( ! section ) {
		return;
	}
	const rows = section.querySelector( SELECTORS.rows );
	const notice = section.querySelector( SELECTORS.notice );
	let pollTimer = null;

	const post = async ( action, data = {} ) => {
		const body = new URLSearchParams( { action, nonce: CONFIG.nonce, ...data } );
		const response = await fetch( CONFIG.ajaxUrl, { method: 'POST', credentials: 'same-origin', body } );
		const json = await response.json().catch( () => ( { success: false, data: { message: `The server answered ${ response.status }.` } } ) );
		if ( ! json.success ) {
			throw new Error( json.data?.message || 'Something went wrong.' );
		}
		return json.data;
	};

	const say = ( text, isError = false ) => {
		notice.textContent = text;
		notice.classList.toggle( 'is-error', isError );
		notice.hidden = ! text;
	};

	const isBusy = () => !! rows.querySelector( 'tr[data-status="queued"], tr[data-status="running"]' );

	const render = ( html ) => {
		rows.innerHTML = html;
		schedulePoll();
	};

	const schedulePoll = () => {
		clearTimeout( pollTimer );
		if ( isBusy() ) {
			pollTimer = setTimeout( poll, POLL_MS );
		}
	};

	const poll = async () => {
		try {
			const data = await post( 'mmi_pipeline_site_export_rows' );
			render( data.html );
		} catch ( e ) {
			say( e.message, true );
			pollTimer = setTimeout( poll, POLL_MS * 2 );
		}
	};

	section.querySelector( SELECTORS.start ).addEventListener( 'click', async ( event ) => {
		const button = event.currentTarget;
		button.disabled = true;
		say( '' );
		try {
			const data = await post( 'mmi_pipeline_site_export_start', { target: section.querySelector( SELECTORS.target ).value } );
			render( data.html );
			say( 'Export started. This list updates by itself until the package is ready.' );
		} catch ( e ) {
			say( e.message, true );
		} finally {
			button.disabled = false;
		}
	} );

	rows.addEventListener( 'click', async ( event ) => {
		const button = event.target.closest( 'button' );
		const row = button?.closest( 'tr[data-job]' );
		if ( ! row ) {
			return;
		}
		const job = row.dataset.job;

		if ( button.matches( SELECTORS.link ) ) {
			button.disabled = true;
			try {
				const data = await post( 'mmi_pipeline_site_export_link', { job, days: section.querySelector( SELECTORS.days ).value } );
				render( data.html );
				let copied = false;
				try {
					await navigator.clipboard.writeText( data.url );
					copied = true;
				} catch ( e ) {
					copied = false;
				}
				say( copied
					? `Link copied. It works until ${ data.expires }, then the package is deleted.`
					: `Copy this link (works until ${ data.expires }): ${ data.url }` );
			} catch ( e ) {
				say( e.message, true );
				button.disabled = false;
			}
			return;
		}

		if ( button.matches( SELECTORS.remove ) ) {
			if ( button.dataset.armed !== '1' ) {
				button.dataset.armed = '1';
				button.textContent = 'Click again to delete';
				setTimeout( () => {
					if ( button.isConnected ) {
						button.dataset.armed = '';
						button.textContent = 'Delete';
					}
				}, ARM_MS );
				return;
			}
			button.disabled = true;
			try {
				const data = await post( 'mmi_pipeline_site_export_delete', { job } );
				render( data.html );
				say( 'Package deleted; its links no longer work.' );
			} catch ( e ) {
				say( e.message, true );
				button.disabled = false;
			}
		}
	} );

	schedulePoll();
}() );
