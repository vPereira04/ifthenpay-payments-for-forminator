/**
 * Frontend behaviour for the ifthenpay field: intercepts submission on any
 * form carrying it (before Forminator's own AJAX submit runs), starts the
 * ifthenpay payment, and — on return — shows the confirmation popup with
 * fast pending→paid resolution (quick check, two-speed poll, visibility
 * re-check, deferred popup for a redirect-type "Paid" confirmation).
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpFmFrontend ) {
		return;
	}

	/**
	 * Query args either we or ifthenpay's own redirect stamp onto the return
	 * URL — must match PaymentController::STALE_RETURN_ARGS. Stripped from
	 * the address bar the instant we land on a return visit, before anything
	 * else runs, so a payment started from this same page load never resends
	 * them as its own `return_url` (that's what stacks Amount/ID with values
	 * from earlier payments once ifthenpay appends its own copy again).
	 */
	var STALE_RETURN_ARGS = [ 'iftp_fm_pay', 'ref', 'transaction_id', 'id', 'amount', 'requestId', 'sk', 'brand', 'pan', 'error' ];

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

	/**
	 * Capture-phase listener on `document` — this runs before any bubble-
	 * phase delegated handler Forminator itself binds (jQuery's `.on()` is
	 * always bubble-phase), which is what makes it possible to reliably
	 * block Forminator's own AJAX submit for a form that has this field,
	 * without depending on script load order between the two plugins.
	 */
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

	/**
	 * Relabels each form's own submit button with the field's
	 * "Checkout button label" setting — this field has no visible button of
	 * its own (see the class docblock), it repurposes the form's existing
	 * submit button, so `data-label` (stamped by `IfthenpayField::markup()`)
	 * has to be applied here rather than left for the field to render itself.
	 */
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
		body.set( 'amount', String( amount ) );
		body.set( 'return_url', window.location.href.split( '#' )[ 0 ] );

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

	/**
	 * Inline error under the field, in Forminator's own error style —
	 * instead of a blocking alert(). An empty message clears it.
	 *
	 * @param {Element} marker  The field's hidden marker input.
	 * @param {string}  message The error to show, or ''.
	 */
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
		field.scrollIntoView( { block: 'center', behavior: 'smooth' } );
	}

	/**
	 * Forminator's conditional-logic engine (front.condition.js) hides a field
	 * by adding `forminator-hidden` (a `display: none !important` class) to
	 * its ancestor `.forminator-row`/`.forminator-col`, not by removing the
	 * marker from the DOM — so without this check, a field ifthenpay is
	 * currently hiding via a condition would still intercept submission and
	 * charge the payer for a field the form itself is presenting as absent.
	 * `.closest()` covers whichever ancestor front.condition.js marked,
	 * without needing to know which one specifically.
	 *
	 * @param {Element} marker The field's hidden marker input.
	 */
	function isConditionallyHidden( marker ) {
		return null !== marker.closest( '.forminator-hidden' );
	}

	/**
	 * Forminator always carries a hidden `form_id` input, but I fall back to
	 * parsing the wrapper's `forminator-module-{id}` id pattern too, in case
	 * a future Forminator version renames that field.
	 */
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

		// Variable amount — best-effort lookup of a same-form field whose
		// name contains the referenced element id (calculation/currency
		// fields render their own visible input under that name).
		// Radios share one name, so the checked one is the real value.
		var variable = marker.getAttribute( 'data-variable' );
		var input = variable
			? form.querySelector( '[name="' + variable + '"]:checked' ) || form.querySelector( '[name="' + variable + '"]:not([type="radio"])' )
			: null;

		return input ? parseFloat( input.value || '0' ) : 0;
	}

	/**
	 * Best-effort element-id => {label, value} snapshot of the submitted
	 * (non-payment) fields, used to replay a real Forminator entry once
	 * payment is confirmed. Keyed by `input.name` — that's each field's real
	 * element_id, the same value Forminator's own fields use for their `name`
	 * attribute and the same value its native Entries screen looks entry meta
	 * up by (`$mapper['meta_key'] = $field->slug` in
	 * admin-page-entries.php::build_field_mapper()). Keying this by the
	 * visible label instead — as an earlier version of this did — silently
	 * broke every column on that screen, since nothing there is ever looked
	 * up by label. The label is still carried along for display purposes
	 * (e.g. the confirmation popup's "Show Entry Data").
	 */
	function collectFields( form, marker ) {
		var fields = {};
		var inputs = form.querySelectorAll( 'input, select, textarea' );

		inputs.forEach( function ( input ) {
			// Forminator's own "Hidden" fields (hidden-N) are real form data;
			// every other hidden input is its internal plumbing (nonce, form_id…).
			var isHiddenField = 'hidden' === input.type && /^hidden-\d+$/.test( input.name );

			if ( input === marker || ( 'hidden' === input.type && ! isHiddenField ) || 'submit' === input.type || ! input.name ) {
				return;
			}

			if ( ( 'checkbox' === input.type || 'radio' === input.type ) && ! input.checked ) {
				return;
			}

			// Checkbox groups/multi-selects are named "checkbox-1[]" — PHP can't
			// parse that inside fields[...][...], so I strip the brackets and
			// join every checked value instead of keeping only the last one.
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

	// ---------------------------------------------------------------------
	// Confirmation popup — fast pending→paid resolution.
	// ---------------------------------------------------------------------

	var context = iftpFmFrontend.return;

	if ( context ) {
		maybeWatchForPaidStatus( context );
	}

	function maybeWatchForPaidStatus( ctx ) {
		var isRedirectPaid = 'paid' === ctx.status && iftpFmFrontend.paidRedirectUrl;

		if ( isRedirectPaid ) {
			window.location.href = iftpFmFrontend.paidRedirectUrl;
			return;
		}

		if ( 'paid' === ctx.status ) {
			showPopup( 'paid' );
			return;
		}

		// Already stored as failed/cancelled by the return page itself
		// (Plugin::resolve_return_context()), so I show it straight away — but
		// keep watching, in case a late webhook still confirms it as paid.
		if ( 'failed' === ctx.status || 'cancelled' === ctx.status ) {
			showPopup( ctx.status );
			watchForPaidStatus( ctx );
			return;
		}

		if ( 'pending' !== ctx.status ) {
			showPopup( ctx.status );
			return;
		}

		// Defer behind a wordless spinner when a redirect-type "Paid"
		// confirmation is configured, instead of flashing "pending" just to
		// immediately replace it with a navigation a moment later.
		var deferPopup = !! iftpFmFrontend.paidRedirectUrl;

		if ( deferPopup ) {
			showSpinner( '' );
		} else {
			showPopup( 'pending' );
		}

		quickCheck( ctx, function ( resolved ) {
			if ( deferPopup ) {
				hideSpinner();
			}

			if ( resolved && 'paid' === resolved.status ) {
				if ( iftpFmFrontend.paidRedirectUrl ) {
					window.location.href = iftpFmFrontend.paidRedirectUrl;
					return;
				}

				showPopup( 'paid' );
				return;
			}

			if ( deferPopup ) {
				showPopup( 'pending' );
			}

			watchForPaidStatus( ctx );
		} );
	}

	function quickCheck( ctx, done ) {
		pollPaymentStatus( ctx ).then( done ).catch( function () {
			done( null );
		} );
	}

	/**
	 * Two-speed poll: a fast burst right after the popup appears (most
	 * returns resolve here), then a slower steady cadence for the long tail
	 * — Multibanco/Payshop references can resolve hours later. A single flat
	 * interval from the start is the exact mistake this replaces.
	 */
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

					if ( iftpFmFrontend.paidRedirectUrl ) {
						window.location.href = iftpFmFrontend.paidRedirectUrl;
					}
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

		// Browsers throttle/suspend setTimeout in a hidden tab — the customer
		// switching to their banking app and back must re-check immediately
		// rather than wait out whatever's left of a throttled interval.
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
				'<table class="iftp-fm-popup-fields"><tbody></tbody></table>' +
				'<button type="button" class="iftp-fm-popup-close"></button>' +
				'</div>';
			popup.querySelector( '.iftp-fm-popup-close' ).textContent = iftpFmFrontend.i18n.close;
			document.body.appendChild( popup );

			popup.querySelector( '.iftp-fm-popup-close' ).addEventListener( 'click', hidePopup );

			// Backdrop click and Escape close it too.
			popup.addEventListener( 'click', function ( e ) {
				if ( e.target === popup ) {
					hidePopup();
				}
			} );

			document.addEventListener( 'keydown', function ( e ) {
				if ( 'Escape' === e.key && 'none' !== popup.style.display ) {
					hidePopup();
				}

				// Only one focusable control, so Tab just stays on it.
				if ( 'Tab' === e.key && 'none' !== popup.style.display && '' !== popup.style.display ) {
					e.preventDefault();
					popup.querySelector( '.iftp-fm-popup-close' ).focus();
				}
			} );
		}

		updatePopup( status );
		popup.style.display = 'flex';
		popup.querySelector( '.iftp-fm-popup-close' ).focus();
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

		popup.className = 'iftp-fm-popup-overlay iftp-fm-popup-overlay--' + status;
		popup.querySelector( '.iftp-fm-popup-title' ).textContent = ( iftpFmFrontend.titles || {} )[ status ] || '';
		popup.querySelector( '.iftp-fm-popup-message' ).textContent = ( iftpFmFrontend.messages || {} )[ status ] || '';
		renderPopupFields( popup, 'paid' === status ? iftpFmFrontend.paidFields || [] : [] );
	}

	/**
	 * Fills the popup's "Submitted Fields" table — built with DOM APIs (not
	 * innerHTML) since labels/values are payer-submitted form data.
	 */
	function renderPopupFields( popup, fields ) {
		var table = popup.querySelector( '.iftp-fm-popup-fields' );
		var tbody = table.querySelector( 'tbody' );

		tbody.innerHTML = '';
		table.style.display = fields.length ? 'table' : 'none';

		fields.forEach( function ( field ) {
			var row = document.createElement( 'tr' );
			var th = document.createElement( 'th' );
			var td = document.createElement( 'td' );

			th.textContent = field.label;
			td.textContent = field.value;

			row.appendChild( th );
			row.appendChild( td );
			tbody.appendChild( row );
		} );
	}
} )();
