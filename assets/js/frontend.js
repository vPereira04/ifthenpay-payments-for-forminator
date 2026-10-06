// Takes over submission of any form with the ifthenpay field and starts the payment; on return, shows
// the status popup until it resolves. A page/URL "Paid" confirmation opens in a new tab, so the form stays put.
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpFmFrontend ) {
		return;
	}

	// Must match PaymentController::STALE_RETURN_ARGS. I strip these on landing, so a payment started from this
	// page doesn't send them back in its return_url, where they'd stack with ifthenpay's own copy.
	var STALE_RETURN_ARGS = [ 'iftp_fm_pay', 'iftp_fm_txn_ref', 'iftp_fm_sig', 'iftp_fm_txn_id', 'id', 'amount', 'requestId', 'sk', 'brand', 'pan', 'error' ];

	( function cleanReturnUrl() {
		if ( ! window.history || ! window.history.replaceState ) {
			return;
		}

		var url = new URL( window.location.href );
		var changed = false;

		STALE_RETURN_ARGS.forEach( function ( key ) {
			if ( url.searchParams.has( key ) ) {
				url.searchParams.delete( key );
				changed = true;
			}
		} );

		if ( changed ) {
			window.history.replaceState( null, '', url.toString() );
		}
	} )();

	// Capture phase runs before Forminator's bubble-phase jQuery submit handler, whatever the script order.
	document.addEventListener(
		'submit',
		function ( e ) {
			var form = e.target;

			if ( ! form || ! form.classList || ! form.classList.contains( 'forminator-custom-form' ) ) {
				return;
			}

			var marker = form.querySelector( '.forminator-ifthenpay-input' );

			if ( ! marker || isConditionallyHidden( marker ) ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			startPayment( form, marker );
		},
		true
	);

	// The field has no button of its own, so I put its checkout label on the form's submit button.
	function applyCheckoutLabels() {
		document.querySelectorAll( '.forminator-ifthenpay-input[data-label]' ).forEach( function ( marker ) {
			var label = marker.getAttribute( 'data-label' );

			if ( ! label ) {
				return;
			}

			var form = marker.closest( 'form.forminator-custom-form' );
			var button = form && form.querySelector( '.forminator-button-submit' );

			if ( button ) {
				button.textContent = label;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', applyCheckoutLabels );

	function startPayment( form, marker ) {
		var amount = resolveAmount( form, marker );

		if ( ! ( amount > 0 ) ) {
			showFieldError( marker, iftpFmFrontend.i18n.error );
			return;
		}

		showFieldError( marker, '' );
		showSpinner( iftpFmFrontend.i18n.redirecting );

		var body = new URLSearchParams();
		body.set( 'action', 'iftp_fm_create_payment' );
		body.set( 'nonce', iftpFmFrontend.nonce );
		body.set( 'form_id', String( resolveFormId( form ) ) );
		body.set( 'field_slug', marker.name || '' );
		body.set( 'return_url', window.location.href.split( '#' )[ 0 ] );
		body.set( 'form_data', JSON.stringify( collectFormData( form ) ) );

		var fields = collectFields( form, marker );
		Object.keys( fields ).forEach( function ( elementId ) {
			body.append( 'fields[' + elementId + '][label]', fields[ elementId ].label );
			body.append( 'fields[' + elementId + '][value]', fields[ elementId ].value );
		} );

		fetch( iftpFmFrontend.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				if ( response.success ) {
					window.location.href = response.data.redirect_url;
					return;
				}

				hideSpinner();
				showFieldError( marker, ( response.data && response.data.message ) || iftpFmFrontend.i18n.error );
			} )
			.catch( function () {
				hideSpinner();
				showFieldError( marker, iftpFmFrontend.i18n.error );
			} );
	}

	// Inline, in Forminator's error style, rather than alert(). An empty message clears it.
	function showFieldError( marker, message ) {
		var field = marker.closest( '.forminator-ifthenpay-field' ) || marker.parentNode;
		var el = field.querySelector( '.iftp-fm-field-error' );

		if ( ! message ) {
			if ( el ) {
				el.remove();
			}

			field.classList.remove( 'forminator-has_error' );
			return;
		}

		if ( ! el ) {
			el = document.createElement( 'span' );
			el.className = 'forminator-error-message iftp-fm-field-error';
			el.setAttribute( 'role', 'alert' );
			field.appendChild( el );
		}

		el.textContent = message;
		field.classList.add( 'forminator-has_error' );
		field.scrollIntoView( { block: 'center', behavior: reducedMotion() ? 'auto' : 'smooth' } );
	}

	// Conditional logic hides a field with .forminator-hidden on an ancestor, not by removing it, and a
	// field the form presents as absent mustn't charge anyone.
	function isConditionallyHidden( marker ) {
		return null !== marker.closest( '.forminator-hidden' );
	}

	// Forminator always renders form_id, but I fall back to the wrapper id in case a future version renames it.
	function resolveFormId( form ) {
		var input = form.querySelector( 'input[name="form_id"]' );

		if ( input && input.value ) {
			return parseInt( input.value, 10 );
		}

		var wrapper = form.closest( '[id^="forminator-module-"]' );
		var match = wrapper ? wrapper.id.match( /(\d+)$/ ) : null;

		return match ? parseInt( match[ 1 ], 10 ) : 0;
	}

	function resolveAmount( form, marker ) {
		var type = marker.getAttribute( 'data-amount-type' );

		if ( 'fixed' === type ) {
			return parseFloat( marker.getAttribute( 'data-amount' ) || '0' );
		}

		// Variable: the input named after the referenced field. Radios share one name, so I take the checked one.
		var variable = marker.getAttribute( 'data-variable' );
		var input = variable
			? form.querySelector( '[name="' + variable + '"]:checked' ) || form.querySelector( '[name="' + variable + '"]:not([type="radio"])' )
			: null;

		return input ? parseFloat( input.value || '0' ) : 0;
	}

	// The raw form, shaped like Forminator's own submit ("checkbox-1[]" becomes an array), so the server
	// can work out a variable amount itself instead of trusting the one I computed here.
	function collectFormData( form ) {
		var data = {};

		new FormData( form ).forEach( function ( value, name ) {
			if ( 'string' !== typeof value ) {
				return;
			}

			if ( /\[\]$/.test( name ) ) {
				var key = name.slice( 0, -2 );
				data[ key ] = ( data[ key ] || [] ).concat( value );
				return;
			}

			data[ name ] = value;
		} );

		return data;
	}

	// element_id => {label, value} for the non-payment fields, replayed as a real entry once paid. Keyed by
	// input name, since Forminator's Entries screen looks meta up by element_id; the label is only for display.
	function collectFields( form, marker ) {
		var fields = {};
		var inputs = form.querySelectorAll( 'input, select, textarea' );

		inputs.forEach( function ( input ) {
			// Forminator's "Hidden" fields (hidden-N) are real data; other hidden inputs are plumbing (nonce, form_id…).
			var isHiddenField = 'hidden' === input.type && /^hidden-\d+$/.test( input.name );

			if ( input === marker || ( 'hidden' === input.type && ! isHiddenField ) || 'submit' === input.type || ! input.name ) {
				return;
			}

			if ( ( 'checkbox' === input.type || 'radio' === input.type ) && ! input.checked ) {
				return;
			}

			// PHP can't parse "checkbox-1[]" inside fields[...][...], so I strip the brackets and join every checked value.
			var name = input.name.replace( /\[\]$/, '' );
			var values = input.multiple
				? Array.prototype.filter.call( input.options, function ( option ) {
					return option.selected;
				} ).map( function ( option ) {
					return option.value;
				} )
				: [ input.value ];

			if ( fields[ name ] ) {
				fields[ name ].value += ', ' + values.join( ', ' );
				return;
			}

			var wrapper = input.closest( '.forminator-field' );
			var labelEl = wrapper ? wrapper.querySelector( 'label' ) : null;
			var label = labelEl ? labelEl.textContent.trim() : name;

			fields[ name ] = { label: label, value: values.join( ', ' ) };
		} );

		return fields;
	}

	function reducedMotion() {
		return !! window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	}

	function showSpinner( label ) {
		var overlay = document.getElementById( 'iftp-fm-spinner' );

		if ( ! overlay ) {
			overlay = document.createElement( 'div' );
			overlay.id = 'iftp-fm-spinner';
			overlay.className = 'iftp-fm-spinner-overlay';
			overlay.setAttribute( 'role', 'status' );
			overlay.setAttribute( 'aria-live', 'polite' );
			overlay.innerHTML = '<div class="iftp-fm-spinner" aria-hidden="true"></div><p class="iftp-fm-spinner-label"></p>';
			document.body.appendChild( overlay );
		}

		// A wordless spinner still needs something for screen readers to say.
		overlay.querySelector( 'p' ).textContent = label || iftpFmFrontend.i18n.confirming;
		overlay.querySelector( 'p' ).classList.toggle( 'iftp-fm-sr-only', ! label );
		overlay.style.display = 'flex';
	}

	function hideSpinner() {
		var overlay = document.getElementById( 'iftp-fm-spinner' );

		if ( overlay ) {
			overlay.style.display = 'none';
		}
	}

	var context = iftpFmFrontend.return;
	var paidPageTried = false;
	var paidPageBlocked = false;
	var shownStatus = '';

	if ( context ) {
		maybeWatchForPaidStatus( context );
	}

	function maybeWatchForPaidStatus( ctx ) {
		showPopup( ctx.status );

		if ( 'pending' === ctx.status ) {
			quickCheck( ctx, function ( resolved ) {
				if ( resolved && 'paid' === resolved.status ) {
					updatePopup( 'paid' );
					return;
				}

				watchForPaidStatus( ctx );
			} );
			return;
		}

		// The return page already stored failed/cancelled, so I show it now but keep watching for a late webhook.
		if ( 'failed' === ctx.status || 'cancelled' === ctx.status ) {
			watchForPaidStatus( ctx );
		}
	}

	function quickCheck( ctx, done ) {
		pollPaymentStatus( ctx ).then( done ).catch( function () {
			done( null );
		} );
	}

	// A fast burst first, since most returns resolve there, then a slow cadence for the long tail:
	// Multibanco/Payshop references can resolve hours later.
	function watchForPaidStatus( ctx ) {
		var fastAttempts = 10;
		var fastIntervalMs = 3000;
		var slowIntervalMs = 10000;
		var maxAttempts = 150; // ~25 minutes at the slow cadence.
		var attempt = 0;
		var timer = null;

		function tick() {
			attempt++;

			pollPaymentStatus( ctx ).then( function ( resolved ) {
				if ( ! resolved ) {
					return;
				}

				updatePopup( resolved.status );

				if ( 'paid' === resolved.status ) {
					stop();
				}
			} ).catch( function () {
				// A dropped request just waits for the next tick.
			} );

			if ( attempt >= maxAttempts ) {
				stop();
				return;
			}

			schedule();
		}

		function schedule() {
			var delay = attempt < fastAttempts ? fastIntervalMs : slowIntervalMs;
			timer = setTimeout( tick, delay );
		}

		function stop() {
			if ( timer ) {
				clearTimeout( timer );
				timer = null;
			}

			document.removeEventListener( 'visibilitychange', onVisible );
		}

		// Hidden tabs throttle timers, so I re-check the moment the payer comes back from their banking app.
		function onVisible() {
			if ( 'visible' === document.visibilityState && timer ) {
				clearTimeout( timer );
				tick();
			}
		}

		document.addEventListener( 'visibilitychange', onVisible );
		schedule();
	}

	function pollPaymentStatus( ctx ) {
		var body = new URLSearchParams();
		body.set( 'action', 'iftp_fm_status' );
		body.set( 'nonce', iftpFmFrontend.statusNonce );
		body.set( 'ref', ctx.ref );
		body.set( 'transaction_id', ctx.transactionId || '' );

		return fetch( iftpFmFrontend.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( response ) {
				return response.success ? response.data : null;
			} );
	}

	function showPopup( status ) {
		var popup = document.getElementById( 'iftp-fm-popup' );

		if ( ! popup ) {
			popup = document.createElement( 'div' );
			popup.id = 'iftp-fm-popup';
			popup.className = 'iftp-fm-popup-overlay';
			popup.innerHTML =
				'<div class="iftp-fm-popup" role="dialog" aria-modal="true" aria-labelledby="iftp-fm-popup-title" aria-describedby="iftp-fm-popup-message">' +
				'<span class="iftp-fm-popup-icon" aria-hidden="true"></span>' +
				'<h2 class="iftp-fm-popup-title" id="iftp-fm-popup-title"></h2>' +
				'<p class="iftp-fm-popup-message" id="iftp-fm-popup-message" aria-live="polite"></p>' +
				'<div class="iftp-fm-popup-details"><div class="iftp-fm-popup-details-inner">' +
				'<table class="iftp-fm-popup-fields"><tbody></tbody></table>' +
				'</div></div>' +
				'<div class="iftp-fm-popup-actions">' +
				'<a class="iftp-fm-popup-continue" target="_blank" rel="noopener"><span class="iftp-fm-popup-continue-label"></span><span class="iftp-fm-sr-only"></span></a>' +
				'<button type="button" class="iftp-fm-popup-close"></button>' +
				'</div>' +
				'</div>';
			popup.querySelector( '.iftp-fm-popup-close' ).textContent = iftpFmFrontend.i18n.close;
			popup.querySelector( '.iftp-fm-popup-continue-label' ).textContent = iftpFmFrontend.i18n.openPage;
			popup.querySelector( '.iftp-fm-popup-continue .iftp-fm-sr-only' ).textContent = ' ' + iftpFmFrontend.i18n.newTab;
			document.body.appendChild( popup );

			popup.querySelector( '.iftp-fm-popup-close' ).addEventListener( 'click', hidePopup );

			// Once they've opened it, there's nothing left for Continue to do.
			popup.querySelector( '.iftp-fm-popup-continue' ).addEventListener( 'click', function () {
				paidPageBlocked = false;
				this.hidden = true;
				popup.querySelector( '.iftp-fm-popup-close' ).focus();
			} );

			// Backdrop click and Escape close it too; a later status change brings it back.
			popup.addEventListener( 'click', function ( e ) {
				if ( e.target === popup ) {
					hidePopup();
				}
			} );

			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && 'none' !== popup.style.display ) {
					hidePopup();
				}

				if ( 'Tab' === e.key && 'none' !== popup.style.display && '' !== popup.style.display ) {
					trapFocus( popup, e );
				}
			} );
		}

		popup.style.display = 'flex';
		popup.querySelector( '.iftp-fm-popup-close' ).focus();
		updatePopup( status );
	}

	// Keeps Tab cycling between the popup's visible buttons.
	function trapFocus( popup, e ) {
		var controls = Array.prototype.filter.call( popup.querySelectorAll( 'a[href], button' ), function ( el ) {
			return ! el.hidden;
		} );
		var index = controls.indexOf( document.activeElement );
		var next = e.shiftKey ? index - 1 : index + 1;

		e.preventDefault();
		controls[ ( next + controls.length ) % controls.length ].focus();
	}

	function hidePopup() {
		var popup = document.getElementById( 'iftp-fm-popup' );

		if ( popup ) {
			popup.style.display = 'none';
		}
	}

	function updatePopup( status ) {
		var popup = document.getElementById( 'iftp-fm-popup' );

		if ( ! popup ) {
			return;
		}

		var changed = status !== shownStatus;
		shownStatus = status;

		// Polling carries on after the payer closes the popup, so a new status (a late "Paid") brings it back.
		if ( changed && 'none' === popup.style.display ) {
			popup.style.display = 'flex';
			popup.querySelector( '.iftp-fm-popup-close' ).focus();
		}

		popup.className = 'iftp-fm-popup-overlay iftp-fm-popup-overlay--' + status;
		popup.querySelector( '.iftp-fm-popup-title' ).textContent = ( iftpFmFrontend.titles || {} )[ status ] || '';
		popup.querySelector( '.iftp-fm-popup-message' ).textContent = ( iftpFmFrontend.messages || {} )[ status ] || '';
		// Only on a change, so a repeat poll doesn't replay the reveal.
		if ( changed ) {
			renderPopupFields( popup, 'paid' === status ? iftpFmFrontend.paidFields || [] : [] );
		}

		if ( 'paid' === status ) {
			openPaidPage();
		}

		// Continue only shows up when the browser blocked the new tab.
		renderPopupContinue( popup, 'paid' === status && paidPageBlocked ? iftpFmFrontend.paidPageUrl : '' );
	}

	// Fallback for a blocked openPaidPage(): a real click always gets past a pop-up blocker.
	function renderPopupContinue( popup, url ) {
		var link = popup.querySelector( '.iftp-fm-popup-continue' );

		if ( url ) {
			link.href = url;
		} else {
			link.removeAttribute( 'href' );
		}

		link.hidden = ! url;

		if ( url ) {
			link.focus();
		}
	}

	// Opens the page/URL "Paid" confirmation in a new tab, once. A null from window.open() means it was blocked.
	function openPaidPage() {
		if ( paidPageTried || ! iftpFmFrontend.paidPageUrl ) {
			return;
		}

		paidPageTried = true;

		// No 'noopener' feature: with it window.open() always returns null, so I cut the opener off by hand.
		var tab = window.open( iftpFmFrontend.paidPageUrl, '_blank' );

		if ( tab ) {
			tab.opener = null;
		}

		paidPageBlocked = ! tab;
	}

	// DOM APIs, not innerHTML: labels and values are payer-submitted.
	function renderPopupFields( popup, fields ) {
		var details = popup.querySelector( '.iftp-fm-popup-details' );
		var tbody = details.querySelector( 'tbody' );

		tbody.innerHTML = '';
		details.classList.remove( 'iftp-fm-popup-details--open' );

		fields.forEach( function ( field, index ) {
			var row = document.createElement( 'tr' );
			var th = document.createElement( 'th' );
			var td = document.createElement( 'td' );

			th.textContent = field.label;
			td.textContent = field.value;

			// Rows land one after another, capped so a long form doesn't keep the payer waiting.
			row.style.animationDelay = ( 200 + Math.min( index, 8 ) * 50 ) + 'ms';

			row.appendChild( th );
			row.appendChild( td );
			tbody.appendChild( row );
		} );

		if ( fields.length ) {
			// Reading offsetHeight commits the collapsed state first, so adding the class animates the expand.
			void details.offsetHeight;
			details.classList.add( 'iftp-fm-popup-details--open' );
		}
	}
} )();
