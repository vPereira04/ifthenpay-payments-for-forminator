// The Connect modal on Forminator's Payments tab, and the Gateway Configuration screen.
( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof iftpFmAdmin ) {
		return;
	}

	function post( action, data ) {
		return $.post( iftpFmAdmin.ajaxUrl, Object.assign( { action: action, nonce: iftpFmAdmin.nonce }, data ) );
	}

	// Forminator's Payments box has no PHP hook inside it, so I move our row in from here.
	// It must land inside .sui-box-body, where SUI scopes settings-row styles; the footer is only its sibling.
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

	// SUI's data-modal-open/close wiring already opens and closes the modal; I only handle the submit.
	$( document ).on( 'click', '#iftp-fm-connect-submit', function ( e ) {
		e.preventDefault();

		var $button = $( this );
		var $label = $button.find( '.sui-loading-text' );
		var $input = $( '#iftp-fm-backoffice-key' );
		var $message = $( '#iftp-fm-connect-modal-message' );
		var key = String( $input.val() || '' ).trim();

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

	// The default star is only selectable while its row is enabled.
	$( document ).on( 'change', '.iftp-fm-method-enabled', function () {
		var $checkbox = $( this );
		var $row = $checkbox.closest( '.iftp-fm-method-row' );
		var $radio = $row.find( '.iftp-fm-default-method' );
		var $star = $row.find( '.iftp-fm-star' );
		var $badge = $row.find( '[data-iftp-status-badge]' );
		var enabled = $checkbox.is( ':checked' );

		$radio.prop( 'disabled', ! enabled );
		$star.toggleClass( 'iftp-fm-star--hidden', ! enabled );

		if ( ! enabled && $radio.is( ':checked' ) ) {
			$radio.prop( 'checked', false );
		}

		// The badge is only a preview; it's saved with the rest of the screen.
		$badge
			.toggleClass( 'iftp-fm-status-badge--enabled', enabled )
			.toggleClass( 'iftp-fm-status-badge--activated', ! enabled )
			.text( $badge.data( enabled ? 'labelEnabled' : 'labelActivated' ) );
	} );

	$( document ).on( 'change', '.iftp-fm-default-method', function () {
		var $star = $( this ).closest( 'td' ).find( '.iftp-fm-star' );

		// Remove, reflow, re-add: that replays the wink even when the same star is picked again.
		$star.removeClass( 'iftp-fm-star--wink' );
		void $star[ 0 ].offsetWidth;
		$star.addClass( 'iftp-fm-star--wink' );
	} );

	// Each method's 24h cooldown is enforced server-side; this only mirrors it.
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

	// Paid always shows the popup; the page and URL rows only show for the type that uses them.
	$( document ).on( 'change', '#iftp-fm-paid-type', function () {
		var type = $( this ).val();

		$( '#iftp-fm-paid-page-row' ).prop( 'hidden', 'page' !== type );
		$( '#iftp-fm-paid-url-row' ).prop( 'hidden', 'url' !== type );
	} );

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

	// One live region by Save for every message on this screen, instead of alert().
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

	$( document ).on( 'submit', '#iftp-fm-settings-form', function ( e ) {
		e.preventDefault();

		var $form = $( this );
		var $button = $( '#iftp-fm-save-settings' );
		var $label = $button.find( '.sui-loading-text' );
		// Kept so the translated label comes back, not a hardcoded English one.
		var originalLabel = $label.data( 'iftpLabel' ) || $label.text();

		$label.data( 'iftpLabel', originalLabel );

		var data = {};

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

		post( 'iftp_fm_save_settings', data )
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
