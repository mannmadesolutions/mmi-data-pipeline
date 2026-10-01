/**
 * MMI License Panel — activate/deactivate form handlers (bundled/vendored
 * copy). Identical across every mmi-* plugin. Do not hand-edit this file in
 * a single plugin; edit the canonical source (mmi-admin/lib/mmi-shared/) and
 * re-sync (sync-to-plugins.sh) to every plugin that bundles it.
 *
 * Talks to MMI_License_UI::ajax_activate()/ajax_deactivate() via the shared
 * mmiGlobal.ajaxUrl. No plugin-specific wiring required — every field this
 * needs is a data-attribute on the form itself.
 */
( function ( $ ) {
	'use strict';

	const SELECTORS = {
		activateForm: '.mmi-license-activate-form',
		deactivateForm: '.mmi-license-deactivate-form',
		keyField: '.mmi-license-key-field',
		message: '.mmi-license-form-message',
	};

	function setBusy( $form, busy ) {
		$form.find( 'button[type="submit"]' ).prop( 'disabled', busy ).toggleClass( 'mmi-is-loading', busy );
	}

	function showMessage( $form, text, isError ) {
		$form.find( SELECTORS.message )
			.text( text )
			.toggleClass( 'mmi-license-form-message--error', !! isError )
			.toggleClass( 'mmi-license-form-message--success', ! isError );
	}

	$( document ).on( 'submit', SELECTORS.activateForm, function ( e ) {
		e.preventDefault();
		const $form = $( this );
		const slug = $form.data( 'mmi-license-slug' );
		const nonce = $form.data( 'mmi-license-nonce' );
		const key = $form.find( SELECTORS.keyField ).val();

		if ( ! key ) {
			showMessage( $form, mmiLicensePanel.enterKeyLabel, true );
			return;
		}

		setBusy( $form, true );
		showMessage( $form, '', false );

		$.post( mmiGlobal.ajaxUrl, {
			action: 'mmi_activate_license',
			nonce: nonce,
			plugin_slug: slug,
			license_key: key,
		} ).done( function ( response ) {
			if ( response && response.success ) {
				showMessage( $form, response.data.message, false );
				window.location.reload();
			} else {
				showMessage( $form, ( response && response.data && response.data.message ) || mmiLicensePanel.genericErrorLabel, true );
			}
		} ).fail( function () {
			showMessage( $form, mmiLicensePanel.genericErrorLabel, true );
		} ).always( function () {
			setBusy( $form, false );
		} );
	} );

	$( document ).on( 'submit', SELECTORS.deactivateForm, function ( e ) {
		e.preventDefault();
		const $form = $( this );
		const slug = $form.data( 'mmi-license-slug' );
		const nonce = $form.data( 'mmi-license-nonce' );

		if ( ! window.confirm( mmiLicensePanel.confirmDeactivateLabel ) ) {
			return;
		}

		setBusy( $form, true );

		$.post( mmiGlobal.ajaxUrl, {
			action: 'mmi_deactivate_license',
			nonce: nonce,
			plugin_slug: slug,
		} ).done( function ( response ) {
			if ( response && response.success ) {
				window.location.reload();
			} else {
				showMessage( $form, ( response && response.data && response.data.message ) || mmiLicensePanel.genericErrorLabel, true );
				setBusy( $form, false );
			}
		} ).fail( function () {
			showMessage( $form, mmiLicensePanel.genericErrorLabel, true );
			setBusy( $form, false );
		} );
	} );
} )( jQuery );
