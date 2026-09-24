/**
 * Admin JS for the two-step ifthenpay settings UX: the "Connect to
 * Ifthenpay" modal on Forminator's native Payments tab, and the Gateway
 * Configuration screen (methods table, default method star toggle,
 * confirmation type, save).
 */
( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof iftpFmAdmin ) {
		return;
	}

	function post( action, data ) {
		return $.post( iftpFmAdmin.ajaxUrl, Object.assign( { action: action, nonce: iftpFmAdmin.nonce }, data ) );
	}

	// Relocate the ifthenpay row into Forminator's native Payments box, as a
	// third row alongside Stripe/PayPal and above its "Save Settings" footer.
	// tab-payments.php is core and has no PHP hook inside that box, so this
	// JS move is the only way in without patching core.
	//
	// Must land inside `.sui-box-body` specifically, not merely before
	// `.sui-box-footer` — Forminator's SUI stylesheet scopes every column/
	// font/spacing rule for a settings row to `.sui-box-body .sui-box-settings-*`
	// (see build/css/shared-ui.min.css), and `.sui-box-footer` is a *sibling*
	// of `.sui-box-body` inside the `<form>`, not a child of it. Inserting
	// before the footer without also being inside the body renders the row
	// unstyled — stacked, wrong font — even though it's visually "above Save".
	$( function () {
		var $source = $( '#iftp-fm-connect-source' );

		if ( ! $source.length ) {
			return;
		}

		var $nativeBody = $( '.sui-box[data-nav="payments"] .sui-box-body' ).first();

		if ( $nativeBody.length ) {
			$nativeBody.append( $source.children() );
		}

		$source.remove();
	} );

	// Connect modal — Forminator's own shared-ui already opens/closes the
	// modal via the generic `data-modal-open`/`data-modal-close` SUI wiring
	// (see the `Connect to Ifthenpay` button and Cancel button markup in
	// ConnectSettings::render_connect_modal()); I only wire the actual submit.
	$( document ).on( 'click', '#iftp-fm-connect-submit', function ( e ) {
		e.preventDefault();

		var $button = $( this );
		var $label = $button.find( '.sui-loading-text' );
		var $input = $( '#iftp-fm-backoffice-key' );
		var $message = $( '#iftp-fm-connect-modal-message' );
		var key = $.trim( $input.val() );

		function fail( message ) {
			$message.text( message );
			$input.attr( 'aria-invalid', 'true' ).trigger( 'focus' );
			$button.prop( 'disabled', false );
			$label.text( iftpFmAdmin.i18n.connect );
		}

		// Same format the server checks, so a typo is caught without a round-trip.
		if ( ! /^\d{4}-\d{4}-\d{4}-\d{4}$/.test( key ) ) {
			fail( iftpFmAdmin.i18n.invalidKey );
			return;
		}

		$message.text( '' );
		$input.removeAttr( 'aria-invalid' );
		$button.prop( 'disabled', true );
		$label.text( iftpFmAdmin.i18n.connecting );

		post( 'iftp_fm_connect_backoffice', { backoffice_key: key } )
			.done( function ( response ) {
				if ( response.success ) {
					window.location.href = response.data.redirect;
					return;
				}

				fail( ( response.data && response.data.message ) || iftpFmAdmin.i18n.error );
			} )
			.fail( function () {
				fail( iftpFmAdmin.i18n.error );
			} );
	} );

	// The key field isn't inside a <form>, so Enter needs wiring by hand.
	$( document ).on( 'keydown', '#iftp-fm-backoffice-key', function ( e ) {
		if ( 'Enter' === e.key ) {
			e.preventDefault();
			$( '#iftp-fm-connect-submit' ).trigger( 'click' );
		}
	} );

	$( document ).on( 'click', '#iftp-fm-disconnect', function ( e ) {
		e.preventDefault();

		var $button = $( this );

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( iftpFmAdmin.i18n.disconnectConfirm ) ) {
			return;
		}

		$button.prop( 'disabled', true );

		post( 'iftp_fm_disconnect_backoffice', {} )
			.done( function () {
				window.location.reload();
			} )
			.fail( function () {
				$button.prop( 'disabled', false );
				$( '#iftp-fm-connect-message' ).text( iftpFmAdmin.i18n.error );
			} );
	} );

	// Gateway Key switch — refetches the methods table for the new key.
	$( document ).on( 'change', '#iftp-fm-gateway-key', function () {
		var $select = $( this );
		var $wrapper = $( '#iftp-fm-methods-table-wrapper' );

		$wrapper.attr( 'aria-busy', 'true' );

		post( 'iftp_fm_select_gateway_key', { gateway_key: $select.val() } )
			.done( function ( response ) {
				if ( response.success ) {
					$wrapper.html( response.data.table_html );
					return;
				}

				showSaveStatus( ( response.data && response.data.message ) || iftpFmAdmin.i18n.error, true );
			} )
			.fail( function () {
				showSaveStatus( iftpFmAdmin.i18n.error, true );
			} )
			.always( function () {
				$wrapper.removeAttr( 'aria-busy' );
			} );
	} );

	// Default Method Selector: the star is only selectable while its row's
	// "Enabled" checkbox is checked. A brief "wink" replays on every toggle —
	// re-triggered via a class add/remove so it can replay on repeated
	// clicks of the same state, which a CSS-only :checked animation can't do.
	$( document ).on( 'change', '.iftp-fm-method-enabled', function () {
		var $checkbox = $( this );
		var $row = $checkbox.closest( '.iftp-fm-method-row' );
		var $radio = $row.find( '.iftp-fm-default-method' );
		var $star = $row.find( '.iftp-fm-star' );

		$radio.prop( 'disabled', ! $checkbox.is( ':checked' ) );
		$star.toggleClass( 'iftp-fm-star--hidden', ! $checkbox.is( ':checked' ) );

		if ( ! $checkbox.is( ':checked' ) && $radio.is( ':checked' ) ) {
			$radio.prop( 'checked', false );
		}

		// Preview only — it's saved with the rest of the screen.
		var $badge = $row.find( '[data-iftp-status-badge]' );
		var enabled = $checkbox.is( ':checked' );

		$badge
			.toggleClass( 'iftp-fm-status-badge--enabled', enabled )
			.toggleClass( 'iftp-fm-status-badge--activated', ! enabled )
			.text( $badge.data( enabled ? 'labelEnabled' : 'labelActivated' ) );
	} );

	$( document ).on( 'change', '.iftp-fm-default-method', function () {
		var $star = $( this ).closest( 'td' ).find( '.iftp-fm-star' );

		$star.removeClass( 'iftp-fm-star--wink' );
		// Force reflow so the class can be re-added immediately after removal.
		void $star[ 0 ].offsetWidth;
		$star.addClass( 'iftp-fm-star--wink' );
	} );

	// Request Activation — one independent 24h cooldown per method, enforced
	// server-side; this only mirrors that state client-side.
	$( document ).on( 'click', '.iftp-fm-request-activation', function () {
		var $button = $( this );
		var entity = $button.data( 'entity' );
		var originalLabel = $button.text();

		$button.prop( 'disabled', true ).text( iftpFmAdmin.i18n.requesting );

		post( 'iftp_fm_request_activation', { entity: entity } )
			.done( function ( response ) {
				if ( response.success ) {
					$button.text( iftpFmAdmin.i18n.requested );
					return;
				}

				showSaveStatus( ( response.data && response.data.message ) || iftpFmAdmin.i18n.error, true );
				$button.prop( 'disabled', false ).text( originalLabel );
			} )
			.fail( function () {
				showSaveStatus( iftpFmAdmin.i18n.error, true );
				$button.prop( 'disabled', false ).text( originalLabel );
			} );
	} );

	// Confirmation Type — Paid gets a 3-way switch; only the matching row
	// (page dropdown / URL field / popup message) is shown at a time.
	$( document ).on( 'change', '#iftp-fm-paid-type', function () {
		var type = $( this ).val();

		$( '#iftp-fm-paid-page-row' ).toggle( 'page' === type );
		$( '#iftp-fm-paid-url-row' ).toggle( 'url' === type );
		$( '#iftp-fm-paid-message-row' ).toggle( 'popup' === type );
	} );

	// Confirmation Type — Paid/Pending/Failed/Cancelled tabs: only one
	// outcome's settings are shown at a time, switched by the tab bar.
	function selectConfirmationTab( $tab ) {
		var status = $tab.data( 'status' );

		$( '.iftp-fm-confirmation-tab' ).each( function () {
			var isActive = this === $tab[ 0 ];

			$( this )
				.toggleClass( 'iftp-fm-confirmation-tab--active', isActive )
				.attr( { 'aria-selected': isActive ? 'true' : 'false', tabindex: isActive ? '0' : '-1' } );
		} );

		$( '.iftp-fm-confirmation-panel' ).each( function () {
			$( this ).prop( 'hidden', $( this ).data( 'status' ) !== status );
		} );
	}

	$( document ).on( 'click', '.iftp-fm-confirmation-tab', function () {
		selectConfirmationTab( $( this ) );
	} );

	$( document ).on( 'keydown', '.iftp-fm-confirmation-tab', function ( e ) {
		var $tabs = $( '.iftp-fm-confirmation-tab' );
		var index = $tabs.index( this );
		var next = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: $tabs.length - 1 }[ e.key ];

		if ( undefined === next ) {
			return;
		}

		e.preventDefault();

		var $next = $tabs.eq( ( next + $tabs.length ) % $tabs.length );

		selectConfirmationTab( $next );
		$next.trigger( 'focus' );
	} );

	// One live region next to Save for every success/error on this screen,
	// instead of blocking alert() dialogs.
	var saveStatusTimer = null;

	function showSaveStatus( message, isError ) {
		var $status = $( '.iftp-fm-save-status' );

		clearTimeout( saveStatusTimer );
		$status
			.text( message )
			.toggleClass( 'iftp-fm-save-status--error', !! isError )
			.addClass( 'iftp-fm-save-status--visible' );

		// Errors stay until the next action; a success fades out.
		if ( ! isError ) {
			saveStatusTimer = setTimeout( function () {
				$status.removeClass( 'iftp-fm-save-status--visible' );
			}, 3000 );
		}
	}

	// Save everything on the Gateway Configuration screen as one unit.
	$( document ).on( 'submit', '#iftp-fm-settings-form', function ( e ) {
		e.preventDefault();

		var $form = $( this );
		var $button = $( '#iftp-fm-save-settings' );
		var $label = $button.find( '.sui-loading-text' );
		// Kept so the translated label comes back, not a hardcoded English one.
		var originalLabel = $label.data( 'iftpLabel' ) || $label.text();

		$label.data( 'iftpLabel', originalLabel );

		var data = { nonce: iftpFmAdmin.nonce, action: 'iftp_fm_save_settings' };

		$form.serializeArray().forEach( function ( field ) {
			if ( 'enabled_methods[]' === field.name ) {
				data.enabled_methods = data.enabled_methods || [];
				data.enabled_methods.push( field.value );
			} else {
				data[ field.name ] = field.value;
			}
		} );

		$button.prop( 'disabled', true );
		$label.text( iftpFmAdmin.i18n.saving );

		$.post( iftpFmAdmin.ajaxUrl, data )
			.done( function ( response ) {
				$button.prop( 'disabled', false );
				$label.text( originalLabel );

				if ( response.success ) {
					$( '#iftp-fm-methods-table-wrapper' ).html( response.data.table_html );
					showSaveStatus( iftpFmAdmin.i18n.saved, false );
				} else {
					showSaveStatus( ( response.data && response.data.message ) || iftpFmAdmin.i18n.error, true );
				}
			} )
			.fail( function () {
				$button.prop( 'disabled', false );
				$label.text( originalLabel );
				showSaveStatus( iftpFmAdmin.i18n.error, true );
			} );
	} );
} )( jQuery );
