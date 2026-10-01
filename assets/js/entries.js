// Payment Entries: the in-place AJAX actions (bulk, new payment, resend, columns) and the UI around them.
// Sort, filter and pagination stay plain GET navigations.
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpFmEntries ) {
		return;
	}

	// Pagination is a full reload, so I keep the selection in sessionStorage to carry it across pages.
	var SELECTION_STORAGE_KEY = 'iftpFmEntriesSelectedRefs';
	var selectedRefsSet = new Set( loadPersistedSelection() );

	// ref => status for the selected rows, so the bulk bar knows statuses on pages that aren't showing.
	var STATUS_STORAGE_KEY = 'iftpFmEntriesSelectedStatus';
	var selectedStatus = loadPersistedStatus();

	// Tells the next page (entries-restore.js reads it) to open already spinning, carrying on from this one.
	var NAV_LOADING_STORAGE_KEY = 'iftpFmEntriesNavLoading';
	var loadingSince = 0;
	var settleTimer = 0;
	// From entries-restore.js (loaded in <head>); without it the spinner just settles straight away.
	var afterSpinLap =
		window.iftpFmAfterSpinLap ||
		function ( scope, done ) {
			done();
			return 0;
		};

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.iftp-fm-entries' );

		if ( ! wrap ) {
			return;
		}

		bindDetailsToggle( wrap );
		bindSelection( wrap );
		bindSelectionCancel( wrap );
		bindRowDelete( wrap );
		bindBulkActions( wrap );
		bindNewEntryModal( wrap );
		bindEmailSettingsModal( wrap );
		bindScrollButton( wrap );
		bindCopyLink( wrap );
		bindResendEmail( wrap );
		bindColumnsMenu( wrap );
		bindFilterPanel( wrap );
		bindPerPage( wrap );
		// Last, so it sees defaultPrevented from every other click/submit handler on wrap.
		bindNavLoading( wrap );

		restoreSelectionState( wrap );
	} );

	// CSS turns the logo mark into the spinner while data-iftp-loading is set. kind 'nav' means the page is
	// about to change, which CSS shows without the AJAX delay.
	function setLoading( wrap, loading, kind ) {
		var body = wrap.querySelector( '[data-iftp-entries-body]' );

		function finish() {
			wrap.removeAttribute( 'data-iftp-loading' );

			if ( body ) {
				body.setAttribute( 'aria-busy', 'false' );
			}
		}

		window.clearTimeout( settleTimer );

		if ( loading ) {
			loadingSince = Date.now();
			wrap.removeAttribute( 'data-iftp-settle' );
			wrap.setAttribute( 'data-iftp-loading', kind || 'ajax' );

			if ( body ) {
				body.setAttribute( 'aria-busy', 'true' );
			}

			return;
		}

		// Over before the spinner ever showed (the 150ms --iftp-fm-mark-delay), so nothing to settle back from.
		if ( Date.now() - loadingSince < 150 ) {
			wrap.setAttribute( 'data-iftp-settle', 'none' );
			finish();
			return;
		}

		settleTimer = afterSpinLap( wrap, finish );
	}

	// Sort, filter, tab and pagination are full page loads — I show the loader until the next page paints.
	function bindNavLoading( wrap ) {
		var currentPage = new URL( window.location.href ).searchParams.get( 'page' );

		function startNav() {
			setLoading( wrap, true, 'nav' );

			try {
				sessionStorage.setItem( NAV_LOADING_STORAGE_KEY, '1' );
			} catch ( e ) {
				// The next page just starts its spinner from the disc instead.
			}
		}

		function isViewUrl( url, pageSlug ) {
			return url.origin === window.location.origin && url.pathname === window.location.pathname && pageSlug === currentPage;
		}

		wrap.addEventListener( 'click', function ( event ) {
			var link = event.target.closest( 'a[href]' );

			if ( ! link || event.defaultPrevented || 0 !== event.button || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey ) {
				return;
			}

			if ( ( link.target && '_self' !== link.target ) || link.hasAttribute( 'download' ) || '#' === link.getAttribute( 'href' ).charAt( 0 ) ) {
				return;
			}

			var url = new URL( link.href, window.location.href );

			if ( isViewUrl( url, url.searchParams.get( 'page' ) ) && ! wrap.hasAttribute( 'data-iftp-bulk-busy' ) ) {
				startNav();
			}
		} );

		wrap.addEventListener( 'submit', function ( event ) {
			var form = event.target;
			var page = form.querySelector( '[name="page"]' );

			if ( event.defaultPrevented || 'get' !== form.method || ! page ) {
				return;
			}

			if ( isViewUrl( new URL( form.action, window.location.href ), page.value ) ) {
				startNav();
			}
		} );

		// Back/forward cache brings the page back exactly as it left — mid-load.
		window.addEventListener( 'pageshow', function ( event ) {
			if ( event.persisted ) {
				setLoading( wrap, false );
			}
		} );
	}

	// Counts in the site's thousands separator, like number_format_i18n() on the PHP side.
	function formatCount( value ) {
		var sep = iftpFmEntries.numberFormat ? iftpFmEntries.numberFormat.thousands : ',';

		return String( parseInt( value, 10 ) || 0 ).replace( /\B(?=(\d{3})+(?!\d))/g, function () {
			return sep;
		} );
	}

	function submitForm( form ) {
		if ( 'function' === typeof form.requestSubmit ) {
			form.requestSubmit(); // runs native validation first
		} else {
			form.submit();
		}
	}

	// Table cells can't use CSS order, so I move the real cells. Check + Reference stay first.
	function applyColumnOrder( wrap, order ) {
		wrap.querySelectorAll( '.iftp-fm-entries-table thead tr, .iftp-fm-entries-table .iftp-fm-entry-row' ).forEach( function ( row ) {
			var cells = {};

			Array.prototype.forEach.call( row.children, function ( cell ) {
				cells[ cell.getAttribute( 'data-col' ) ] = cell;
			} );

			order.forEach( function ( slug ) {
				if ( cells[ slug ] ) {
					row.appendChild( cells[ slug ] );
				}
			} );
		} );
	}

	function rootColumnOrder( wrap ) {
		return ( wrap.getAttribute( 'data-iftp-col-order' ) || '' ).split( ' ' ).filter( Boolean );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', iftpFmEntries.nonce );

		Object.keys( data ).forEach( function ( key ) {
			( Array.isArray( data[ key ] ) ? data[ key ] : [ data[ key ] ] ).forEach( function ( value ) {
				body.append( Array.isArray( data[ key ] ) ? key + '[]' : key, value );
			} );
		} );

		return fetch( iftpFmEntries.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} );
	}

	// Every AJAX action answers with a re-rendered view for the current URL, swapped in here instead of a reload.
	function applyViewResponse( wrap, data ) {
		var body = wrap.querySelector( '[data-iftp-entries-body]' );
		var pagination = wrap.querySelector( '[data-iftp-pagination]' );
		var totalCount = wrap.querySelector( '[data-iftp-total-count]' );
		var summary = wrap.querySelector( '[data-iftp-summary]' );

		if ( body && 'string' === typeof data.rowsHtml ) {
			body.innerHTML = data.rowsHtml;
			// Only first paint gets the drop-in; after an action the mark settles out of the loader instead.
			body.querySelectorAll( '.iftp-fm-empty-state__icon' ).forEach( function ( icon ) {
				icon.classList.add( 'iftp-fm-mark--no-entrance' );
			} );
			// A column save may still be in flight, so the server's rows can be in the old order.
			applyColumnOrder( wrap, rootColumnOrder( wrap ) );
		}

		if ( pagination && 'string' === typeof data.paginationHtml ) {
			pagination.innerHTML = data.paginationHtml;
		}

		if ( totalCount && 'string' === typeof data.totalLabel ) {
			totalCount.textContent = data.totalLabel;
		}

		if ( summary && 'string' === typeof data.summaryHtml ) {
			summary.innerHTML = data.summaryHtml;
		}

		if ( totalCount && 'number' === typeof data.total ) {
			totalCount.setAttribute( 'data-iftp-total', data.total );
		}

		if ( 'number' === typeof data.paged ) {
			syncPaged( data.paged );
		}

		// Re-check the rows still in the cross-page selection rather than clearing it.
		restoreSelectionState( wrap );
	}

	// Emptying the last page makes the server fall back a page, so the URL and my next posts follow it.
	function syncPaged( paged ) {
		var url = new URL( window.location.href );

		iftpFmEntries.currentFilters.paged = paged;

		if ( paged > 1 ) {
			url.searchParams.set( 'paged', paged );
		} else {
			url.searchParams.delete( 'paged' );
		}

		if ( url.href !== window.location.href ) {
			window.history.replaceState( window.history.state, '', url.href );
		}
	}

	// prefix is '' or 'view_', matching PaymentEntriesPage::view_state_from_request().
	function currentViewParams( prefix ) {
		var f = iftpFmEntries.currentFilters;
		var params = {};

		params[ prefix + 's' ] = f.s;
		// Always view_status: a bare 'status' would clash with the bulk status change's target status.
		params.view_status = f.status;
		params[ prefix + 'form_id' ] = f.form_id;
		params[ prefix + 'date_from' ] = f.date_from;
		params[ prefix + 'date_to' ] = f.date_to;
		params[ prefix + 'pay_method' ] = f.pay_method || '';
		params[ prefix + 'amount_min' ] = f.amount_min || '';
		params[ prefix + 'amount_max' ] = f.amount_max || '';
		params[ prefix + 'orderby' ] = f.orderby;
		params[ prefix + 'order' ] = f.order;
		params[ prefix + 'per_page' ] = f.per_page;
		params[ prefix + 'paged' ] = f.paged;

		return params;
	}

	// The details rows arrive empty; the first open fetches the panel and the row keeps it after that.
	// An AJAX re-render swaps the rows, so a panel is never older than the table around it.
	function bindDetailsToggle( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '.iftp-fm-details-toggle' );

			if ( ! toggle ) {
				return;
			}

			var target = document.getElementById( toggle.getAttribute( 'data-details-target' ) );

			if ( ! target ) {
				return;
			}

			target.hidden = ! target.hidden;
			toggle.setAttribute( 'aria-expanded', target.hidden ? 'false' : 'true' );

			if ( ! target.hidden ) {
				loadDetails( target );
			}
		} );
	}

	function loadDetails( detailsRow ) {
		var cell = detailsRow.firstElementChild;

		if ( ! cell || detailsRow.hasAttribute( 'data-iftp-details-state' ) ) {
			return;
		}

		function showStatus( message, isError ) {
			var panel = document.createElement( 'div' );

			panel.className = 'iftp-fm-details-panel iftp-fm-details-panel--status' + ( isError ? ' iftp-fm-details-panel--error' : '' );
			panel.setAttribute( 'role', isError ? 'alert' : 'status' );
			panel.textContent = message;
			cell.replaceChildren( panel );
		}

		detailsRow.setAttribute( 'data-iftp-details-state', 'loading' );
		cell.setAttribute( 'aria-busy', 'true' );
		showStatus( iftpFmEntries.i18n.detailsLoading, false );

		post( 'iftp_fm_entry_details', { ref: detailsRow.getAttribute( 'data-iftp-details-ref' ) } )
			.then( function ( response ) {
				if ( ! response || ! response.success || 'string' !== typeof response.data.html ) {
					return Promise.reject( response && response.data && response.data.message );
				}

				cell.innerHTML = response.data.html;
				detailsRow.setAttribute( 'data-iftp-details-state', 'loaded' );
			} )
			.catch( function ( message ) {
				// Cleared, so closing and opening the panel again retries.
				detailsRow.removeAttribute( 'data-iftp-details-state' );
				showStatus( 'string' === typeof message && message ? message : iftpFmEntries.i18n.detailsError, true );
			} )
			.then( function () {
				cell.removeAttribute( 'aria-busy' );
			} );
	}

	function loadPersistedSelection() {
		try {
			var raw = sessionStorage.getItem( SELECTION_STORAGE_KEY );
			var refs = raw ? JSON.parse( raw ) : [];

			return Array.isArray( refs ) ? refs : [];
		} catch ( e ) {
			return [];
		}
	}

	function loadPersistedStatus() {
		try {
			var map = JSON.parse( sessionStorage.getItem( STATUS_STORAGE_KEY ) || '{}' );

			return map && 'object' === typeof map ? map : {};
		} catch ( e ) {
			return {};
		}
	}

	function persistSelection() {
		var statuses = {};

		// Pruned to the selection, so it can't grow past it.
		selectedRefsSet.forEach( function ( ref ) {
			if ( selectedStatus[ ref ] ) {
				statuses[ ref ] = selectedStatus[ ref ];
			}
		} );

		selectedStatus = statuses;

		try {
			sessionStorage.setItem( SELECTION_STORAGE_KEY, JSON.stringify( Array.from( selectedRefsSet ) ) );
			sessionStorage.setItem( STATUS_STORAGE_KEY, JSON.stringify( selectedStatus ) );
		} catch ( e ) {
			// No storage, so the selection just won't survive a page turn.
		}
	}

	function visibleRowCheckboxes( wrap ) {
		return Array.prototype.slice.call( wrap.querySelectorAll( '[data-iftp-row-check]' ) );
	}

	// Only drops the refs an action consumed, so a single-row delete never wipes a cross-page selection.
	function removeRefsFromSelection( refs ) {
		var changed = false;

		refs.forEach( function ( ref ) {
			if ( selectedRefsSet.delete( ref ) ) {
				changed = true;
			}
		} );

		if ( changed ) {
			persistSelection();
		}
	}

	function selectRow( checkbox ) {
		selectedRefsSet.add( checkbox.value );
		selectedStatus[ checkbox.value ] = checkbox.getAttribute( 'data-iftp-status' ) || '';
	}

	// Freshly rendered rows start unchecked, so I re-sync them (and select-all) to the stored selection.
	// Their statuses are the freshest I have, so they overwrite what I stored.
	function restoreSelectionState( wrap ) {
		var checkboxes = visibleRowCheckboxes( wrap );
		var statusChanged = false;

		checkboxes.forEach( function ( checkbox ) {
			checkbox.checked = selectedRefsSet.has( checkbox.value );

			if ( checkbox.checked && selectedStatus[ checkbox.value ] !== checkbox.getAttribute( 'data-iftp-status' ) ) {
				selectRow( checkbox );
				statusChanged = true;
			}
		} );

		if ( statusChanged ) {
			persistSelection();
		}

		var selectAll = wrap.querySelector( '[data-iftp-select-all]' );

		if ( selectAll ) {
			selectAll.checked = checkboxes.length > 0 && checkboxes.every( function ( checkbox ) {
				return checkbox.checked;
			} );
		}

		refreshSelectionUI( wrap );
	}

	// Includes refs selected on other pages.
	function clearSelection( wrap ) {
		selectedRefsSet.clear();
		persistSelection();
		restoreSelectionState( wrap );
	}

	function bindSelection( wrap ) {
		var selectAll = wrap.querySelector( '[data-iftp-select-all]' );

		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				visibleRowCheckboxes( wrap ).forEach( function ( checkbox ) {
					checkbox.checked = selectAll.checked;

					if ( selectAll.checked ) {
						selectRow( checkbox );
					} else {
						selectedRefsSet.delete( checkbox.value );
					}
				} );

				persistSelection();
				refreshSelectionUI( wrap );
			} );
		}

		wrap.addEventListener( 'change', function ( event ) {
			if ( ! event.target.matches( '[data-iftp-row-check]' ) ) {
				return;
			}

			if ( event.target.checked ) {
				selectRow( event.target );
			} else {
				selectedRefsSet.delete( event.target.value );
			}

			persistSelection();
			restoreSelectionState( wrap );
		} );
	}

	// The footer Cancel and the bulk-bar × both hide themselves, so I move focus to select-all.
	function bindSelectionCancel( wrap ) {
		wrap.querySelectorAll( '[data-iftp-selection-cancel]' ).forEach( function ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				var selectAll = wrap.querySelector( '[data-iftp-select-all]' );

				clearSelection( wrap );

				if ( selectAll ) {
					selectAll.focus();
				}
			} );
		} );
	}

	function refreshSelectionUI( wrap ) {
		var bar = wrap.querySelector( '[data-iftp-bulk-actions]' );
		var rangeLabel = wrap.querySelector( '[data-iftp-total-count]' );
		var selectionSummary = wrap.querySelector( '[data-iftp-selection-summary]' );
		var selectionText = wrap.querySelector( '[data-iftp-selection-text]' );
		var bulkCount = wrap.querySelector( '[data-iftp-bulk-count]' );
		var count = selectedRefsSet.size;
		var hasSelection = count > 0;
		var total = rangeLabel ? parseInt( rangeLabel.getAttribute( 'data-iftp-total' ) || '0', 10 ) : 0;

		// CSS keys selection mode (bulk bar in place of the tabs) off this.
		wrap.toggleAttribute( 'data-iftp-has-selection', hasSelection );

		if ( bar ) {
			bar.hidden = ! hasSelection;
		}

		if ( bulkCount && iftpFmEntries.i18n.bulkSelectedCount ) {
			bulkCount.textContent = iftpFmEntries.i18n.bulkSelectedCount.replace( '%s', formatCount( count ) );
		}

		refreshBulkOptions( wrap );

		if ( rangeLabel ) {
			rangeLabel.hidden = hasSelection;
		}

		if ( selectionSummary ) {
			selectionSummary.hidden = ! hasSelection;
		}

		if ( selectionText ) {
			selectionText.textContent = iftpFmEntries.i18n.selectedOfTotal
				.replace( '%1$s', formatCount( count ) )
				.replace( '%2$s', formatCount( total ) );
		}
	}

	// "Send payment email" only makes sense when every selected entry is pending, and Delete when at least
	// one isn't paid. A ref with no stored status (selected before this shipped) counts as neither pending
	// nor paid. The server re-checks each one anyway.
	function refreshBulkOptions( wrap ) {
		var refs = Array.from( selectedRefsSet );

		setBulkOption(
			wrap.querySelector( '[data-iftp-bulk-email]' ),
			refs.length > 0 && refs.every( function ( ref ) {
				return 'pending' === selectedStatus[ ref ];
			} ),
			true
		);

		setBulkOption( wrap.querySelector( '[data-iftp-bulk-delete]' ), deletableRefs( refs ).length > 0, false );
	}

	// Safari ignores hidden on <option>, so disabled keeps it unpickable there.
	function setBulkOption( option, available, hideWhenOff ) {
		if ( ! option ) {
			return;
		}

		option.disabled = ! available;

		if ( hideWhenOff ) {
			option.hidden = ! available;
		}

		if ( ! available && option.selected ) {
			option.parentNode.value = '';
		}
	}

	// Paid entries are payment history: the server won't delete them, so I don't send them.
	function deletableRefs( refs ) {
		return refs.filter( function ( ref ) {
			return 'paid' !== selectedStatus[ ref ];
		} );
	}

	function bindRowDelete( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			var trigger = event.target.closest( '[data-iftp-row-delete]' );

			if ( ! trigger ) {
				return;
			}

			var ref = trigger.getAttribute( 'data-iftp-row-delete' );

			confirmAction(
				iftpFmEntries.i18n.confirmDeleteTitle,
				iftpFmEntries.i18n.confirmDeleteMessage,
				iftpFmEntries.i18n.confirmDeleteButton,
				true,
				function () {
					deleteEntries( wrap, [ ref ] );
				}
			);
		} );
	}

	function deleteEntries( wrap, refs ) {
		return runBulk( wrap, 'iftp_fm_delete_entries', refs, {}, {
			progress: iftpFmEntries.i18n.bulkDeleting,
			done: iftpFmEntries.i18n.deleteToastSuccess,
			error: iftpFmEntries.i18n.deleteError,
		} );
	}

	function updateStatus( wrap, refs, status ) {
		return runBulk( wrap, 'iftp_fm_update_status', refs, { status: status }, {
			progress: iftpFmEntries.i18n.bulkUpdating,
			done: iftpFmEntries.i18n.statusToastSuccess,
			error: iftpFmEntries.i18n.updateStatusError,
		} );
	}

	// Posts refs a chunk at a time, re-rendering after each so the table and count move as it goes.
	// On an error I stop, leaving the rest selected. Resolves to whether every chunk went through.
	function runBulk( wrap, action, refs, extra, text ) {
		var size = Math.max( 1, parseInt( iftpFmEntries.bulkChunkSize, 10 ) || 250 );
		var chunks = [];
		var done = 0;

		for ( var i = 0; i < refs.length; i += size ) {
			chunks.push( refs.slice( i, i + size ) );
		}

		function progress() {
			// One request finishes too fast for a progress message to be worth showing.
			if ( chunks.length > 1 ) {
				showToast( text.progress.replace( '%1$s', formatCount( done ) ).replace( '%2$s', formatCount( refs.length ) ), false, true );
			}
		}

		function fail( message ) {
			if ( done > 0 ) {
				message = iftpFmEntries.i18n.bulkPartialError.replace( '%1$s', formatCount( done ) ).replace( '%2$s', formatCount( refs.length ) );
			}

			showToast( message || text.error, true );

			return false;
		}

		setLoading( wrap, true );
		progress();

		return chunks
			.reduce( function ( chain, chunk ) {
				return chain.then( function ( ok ) {
					if ( ! ok ) {
						return false;
					}

					return post( action, Object.assign( { refs: JSON.stringify( chunk ) }, extra, currentViewParams( '' ) ) ).then( function ( response ) {
						if ( ! response.success ) {
							return fail( response.data && response.data.message );
						}

						done += chunk.length;
						removeRefsFromSelection( chunk );
						applyViewResponse( wrap, response.data );
						progress();

						return true;
					} );
				} );
			}, Promise.resolve( true ) )
			.then(
				function ( ok ) {
					if ( ok ) {
						showToast( text.done );
					}

					return ok;
				},
				function () {
					return fail();
				}
			)
			.then( function ( ok ) {
				setLoading( wrap, false );

				return ok;
			} );
	}

	// Its own runner, not runBulk(): a request may hand back fewer refs than I sent (it ran out of time),
	// and the outcome is a per-ref tally, not all-or-nothing. Sent and skipped refs leave the selection;
	// failed ones stay, so Apply again retries just those.
	function sendPaymentEmails( wrap, refs ) {
		var size = Math.max( 1, parseInt( iftpFmEntries.emailChunkSize, 10 ) || 10 );
		var queue = refs.slice();
		var tally = { sent: 0, skipped: 0, failed: 0 };

		function handled() {
			return tally.sent + tally.skipped + tally.failed;
		}

		function progress() {
			showToast( iftpFmEntries.i18n.bulkEmailing.replace( '%1$s', formatCount( handled() ) ).replace( '%2$s', formatCount( refs.length ) ), false, true );
		}

		function summary() {
			var parts = [ iftpFmEntries.i18n.bulkEmailSent.replace( '%s', formatCount( tally.sent ) ) ];

			if ( tally.skipped ) {
				parts.push( iftpFmEntries.i18n.bulkEmailSkipped.replace( '%s', formatCount( tally.skipped ) ) );
			}

			if ( tally.failed ) {
				parts.push( iftpFmEntries.i18n.bulkEmailFailed.replace( '%s', formatCount( tally.failed ) ) );
			}

			return parts.join( ' ' );
		}

		function next() {
			if ( 0 === queue.length ) {
				return Promise.resolve( true );
			}

			var chunk = queue.slice( 0, size );
			// Only the last chunk asks for the re-rendered table; the ones before would redraw it for nothing.
			var params = { refs: JSON.stringify( chunk ), render: chunk.length === queue.length ? '1' : '' };

			return post( 'iftp_fm_send_payment_emails', Object.assign( params, currentViewParams( '' ) ) ).then( function ( response ) {
				var data = response && response.data;

				if ( ! response || ! response.success || ! Array.isArray( data.sent ) ) {
					return Promise.reject( data && data.message );
				}

				var done = new Set( data.sent.concat( data.skipped, data.failed ) );

				// Nothing handled would loop forever; treat it as an error.
				if ( 0 === done.size ) {
					return Promise.reject();
				}

				queue = queue.filter( function ( ref ) {
					return ! done.has( ref );
				} );

				tally.sent += data.sent.length;
				tally.skipped += data.skipped.length;
				tally.failed += data.failed.length;

				removeRefsFromSelection( data.sent.concat( data.skipped ) );

				if ( 'string' === typeof data.rowsHtml ) {
					applyViewResponse( wrap, data );
				} else {
					restoreSelectionState( wrap );
				}

				progress();

				return next();
			} );
		}

		setLoading( wrap, true );
		progress();

		return next()
			.then(
				function () {
					showToast( summary(), tally.failed > 0 );

					return 0 === tally.failed;
				},
				function ( message ) {
					// What's left (the unsent chunk onwards, plus any failures) is still selected.
					showToast( handled() > 0 ? summary() + ' ' + iftpFmEntries.i18n.bulkPartialError.replace( '%1$s', formatCount( handled() ) ).replace( '%2$s', formatCount( refs.length ) ) : message || iftpFmEntries.i18n.resendError, true );

					return false;
				}
			)
			.then( function ( ok ) {
				setLoading( wrap, false );

				return ok;
			} );
	}

	function warnBeforeLeave( event ) {
		event.preventDefault();
		event.returnValue = iftpFmEntries.i18n.bulkLeaveWarning;

		return event.returnValue;
	}

	// Locks the bulk bar while a run is in flight, and warns before leaving mid-run.
	function setBulkBusy( wrap, busy ) {
		wrap.toggleAttribute( 'data-iftp-bulk-busy', busy );

		wrap.querySelectorAll( '[data-iftp-bulk-apply], [data-iftp-bulk-select], [data-iftp-selection-cancel]' ).forEach( function ( el ) {
			el.disabled = busy;
		} );

		if ( busy ) {
			window.addEventListener( 'beforeunload', warnBeforeLeave );
		} else {
			window.removeEventListener( 'beforeunload', warnBeforeLeave );
		}
	}

	function runLocked( wrap, run ) {
		setBulkBusy( wrap, true );

		run().then( function () {
			setBulkBusy( wrap, false );
		} );
	}

	function bindBulkActions( wrap ) {
		var applyBtn = wrap.querySelector( '[data-iftp-bulk-apply]' );
		var select = wrap.querySelector( '[data-iftp-bulk-select]' );

		if ( ! applyBtn || ! select ) {
			return;
		}

		applyBtn.addEventListener( 'click', function () {
			var value = select.value;
			var refs = Array.from( selectedRefsSet );

			// I skip the confirm here: exporting changes nothing.
			if ( 'export' === value ) {
				if ( 0 === refs.length ) {
					showToast( iftpFmEntries.i18n.exportNothingSelected, true );
					return;
				}

				exportEntries( refs );
				select.value = '';
				return;
			}

			if ( '' === value || 0 === refs.length ) {
				return;
			}

			if ( 'delete' === value ) {
				var deletable = deletableRefs( refs );
				var keptPaid = refs.length - deletable.length;

				if ( 0 === deletable.length ) {
					return;
				}

				confirmAction(
					iftpFmEntries.i18n.confirmDeleteTitle,
					iftpFmEntries.i18n.confirmDeleteMessage + ( keptPaid > 0 ? ' ' + iftpFmEntries.i18n.deleteKeepsPaid.replace( '%s', formatCount( keptPaid ) ) : '' ),
					iftpFmEntries.i18n.confirmDeleteButton,
					true,
					function () {
						select.value = '';
						runLocked( wrap, function () {
							return deleteEntries( wrap, deletable );
						} );
					}
				);
				return;
			}

			if ( 'email' === value ) {
				confirmAction(
					iftpFmEntries.i18n.bulkEmailTitle,
					iftpFmEntries.i18n.bulkEmailMessage.replace( '%s', formatCount( refs.length ) ),
					iftpFmEntries.i18n.bulkEmailButton,
					false,
					function () {
						select.value = '';
						runLocked( wrap, function () {
							return sendPaymentEmails( wrap, refs );
						} );
					}
				);
				return;
			}

			var label = iftpFmEntries.statusLabels[ value ] || value;

			confirmAction(
				iftpFmEntries.i18n.confirmStatusTitle,
				iftpFmEntries.i18n.confirmStatusMessage.replace( '%s', label ),
				iftpFmEntries.i18n.confirmStatusButton,
				false,
				function () {
					select.value = '';
					runLocked( wrap, function () {
						return updateStatus( wrap, refs, value );
					} );
				}
			);
		} );
	}

	// A throwaway form post, so the browser handles the CSV download itself.
	function exportEntries( refs ) {
		var url = new URL( iftpFmEntries.exportUrl, window.location.href );
		var form = document.createElement( 'form' );
		var f = iftpFmEntries.currentFilters;

		url.searchParams.set( 'action', 'iftp_fm_export' );
		form.method = 'post';
		form.action = url.toString();
		form.hidden = true;

		function addField( name, value ) {
			var input = document.createElement( 'input' );
			input.type = 'hidden';
			input.name = name;
			input.value = value;
			form.appendChild( input );
		}

		addField( '_wpnonce', iftpFmEntries.exportNonce );
		addField( 'orderby', f.orderby || '' );
		addField( 'order', f.order || '' );
		// One JSON field, not refs[] — PHP drops input vars past max_input_vars (1000 by default).
		addField( 'refs', JSON.stringify( refs ) );

		document.body.appendChild( form );
		form.submit();
		form.remove();
	}

	function bindNewEntryModal( wrap ) {
		var trigger = wrap.querySelector( '[data-iftp-new-entry-trigger]' );
		var modal = wrap.querySelector( '[data-iftp-new-entry-modal]' );

		if ( ! trigger || ! modal ) {
			return;
		}

		var overlay = modal.querySelector( '[data-iftp-new-entry-overlay]' );
		var cancelBtn = modal.querySelector( '[data-iftp-new-entry-cancel]' );
		var form = modal.querySelector( '[data-iftp-new-entry-form]' );
		var errorEl = modal.querySelector( '[data-iftp-new-entry-error]' );
		var submitBtn = modal.querySelector( '[data-iftp-new-entry-submit]' );
		var submitLabel = submitBtn.textContent;

		function open() {
			modal.hidden = false;
			errorEl.hidden = true;
			trapFocus( modal );
			form.querySelector( 'input, select' ).focus();
		}

		function close() {
			modal.hidden = true;
			form.reset();
			releaseFocus( modal );
			trigger.focus();
		}

		trigger.addEventListener( 'click', open );
		cancelBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'click', close );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden ) {
				close();
			}
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var formData = new FormData( form );

			submitBtn.disabled = true;
			submitBtn.textContent = iftpFmEntries.i18n.newEntryCreating;
			errorEl.hidden = true;
			setLoading( wrap, true );

			post(
				'iftp_fm_create_entry',
				Object.assign(
					{
						customer_name: formData.get( 'customer_name' ) || '',
						customer_email: formData.get( 'customer_email' ) || '',
						amount: formData.get( 'amount' ) || '0',
						status: formData.get( 'status' ) || '',
					},
					currentViewParams( 'view_' )
				)
			)
				.then( function ( response ) {
					submitBtn.disabled = false;
					submitBtn.textContent = submitLabel;

					if ( ! response.success ) {
						setLoading( wrap, false );
						errorEl.textContent = ( response.data && response.data.message ) || iftpFmEntries.i18n.newEntryError;
						errorEl.hidden = false;
						return;
					}

					applyViewResponse( wrap, response.data );
					setLoading( wrap, false );
					showToast( iftpFmEntries.i18n.newEntryToastSuccess );
					close();
				} )
				.catch( function () {
					setLoading( wrap, false );
					submitBtn.disabled = false;
					submitBtn.textContent = submitLabel;
					errorEl.textContent = iftpFmEntries.i18n.newEntryError;
					errorEl.hidden = false;
				} );
		} );
	}

	// The "Payment email" editor. Closing throws away unsaved edits, so with any pending I only close on Cancel.
	function bindEmailSettingsModal( wrap ) {
		var trigger = wrap.querySelector( '[data-iftp-email-trigger]' );
		var modal = wrap.querySelector( '[data-iftp-email-modal]' );
		var config = iftpFmEntries.emailSettings;

		if ( ! trigger || ! modal || ! config ) {
			return;
		}

		var form = modal.querySelector( '[data-iftp-email-form]' );
		var subjectInput = modal.querySelector( '[data-iftp-email-subject]' );
		var bodyArea = modal.querySelector( '[data-iftp-email-body]' );
		var errorEl = modal.querySelector( '[data-iftp-email-error]' );
		var submitBtn = modal.querySelector( '[data-iftp-email-submit]' );
		var submitLabel = submitBtn.textContent;
		var previewWrap = modal.querySelector( '[data-iftp-email-preview-wrap]' );
		var previewFrame = modal.querySelector( '[data-iftp-email-preview]' );
		var previewSubject = modal.querySelector( '[data-iftp-email-preview-subject]' );
		var tagDesc = modal.querySelector( '[data-iftp-email-tag-desc]' );
		var tagDescDefault = tagDesc.textContent;

		var saved = { subject: subjectInput.value, body: bodyArea.value };
		var editor = null;
		// Where a clicked tag goes: whichever field was focused last.
		var tagTarget = 'body';
		var previewTimer = null;
		var previewSeq = 0;
		var mediaFrame = null;
		var mediaBtn = modal.querySelector( '[data-iftp-email-media]' );

		// The email body is ~536px wide inside its card, so I cap the image there.
		var IMAGE_MAX_WIDTH = 536;

		function getBody() {
			return editor ? editor.getValue() : bodyArea.value;
		}

		function setBody( value ) {
			if ( editor ) {
				editor.setValue( value );
				editor.clearHistory();
			} else {
				bodyArea.value = value;
			}
		}

		// CodeMirror can't measure itself inside a hidden modal, so I only start it on first open.
		function ensureEditor() {
			if ( editor || ! config.editor || ! window.wp || ! window.wp.codeEditor ) {
				return;
			}

			editor = window.wp.codeEditor.initialize( bodyArea, config.editor ).codemirror;
			editor.on( 'change', schedulePreview );
			editor.on( 'focus', function () {
				tagTarget = 'body';
			} );
		}

		function isDirty() {
			return subjectInput.value !== saved.subject || getBody() !== saved.body;
		}

		function showError( message ) {
			errorEl.textContent = message;
			errorEl.hidden = false;
		}

		function open() {
			modal.hidden = false;
			errorEl.hidden = true;
			ensureEditor();

			if ( editor ) {
				editor.refresh();
			}

			trapFocus( modal );
			subjectInput.focus();
			refreshPreview();
		}

		function close() {
			clearTimeout( previewTimer );
			modal.hidden = true;
			subjectInput.value = saved.subject;
			setBody( saved.body );
			tagDesc.textContent = tagDescDefault;
			releaseFocus( modal );
			trigger.focus();
		}

		function schedulePreview() {
			if ( modal.hidden ) {
				return;
			}

			clearTimeout( previewTimer );
			previewTimer = setTimeout( refreshPreview, parseInt( config.previewDelay, 10 ) || 400 );
		}

		function refreshPreview() {
			var seq = ++previewSeq;

			previewWrap.classList.add( 'iftp-fm-email-preview__frame--loading' );

			post( 'iftp_fm_preview_payment_email', { subject: subjectInput.value, body: getBody() } )
				.then( function ( response ) {
					if ( ! response.success ) {
						throw ( response.data && response.data.message ) || '';
					}

					return response.data;
				} )
				.then(
					function ( data ) {
						// A slower, older preview mustn't land on top of a newer one.
						if ( seq !== previewSeq ) {
							return;
						}

						previewSubject.textContent = data.subject;
						previewFrame.srcdoc = data.html;
						previewWrap.classList.remove( 'iftp-fm-email-preview__frame--loading' );
					},
					function ( message ) {
						if ( seq !== previewSeq ) {
							return;
						}

						previewSubject.textContent = 'string' === typeof message && message ? message : iftpFmEntries.i18n.emailPreviewError;
						previewWrap.classList.remove( 'iftp-fm-email-preview__frame--loading' );
					}
				);
		}

		function insertTag( tag ) {
			if ( 'subject' === tagTarget ) {
				subjectInput.setRangeText( tag, subjectInput.selectionStart, subjectInput.selectionEnd, 'end' );
				subjectInput.focus();
				schedulePreview();
				return;
			}

			insertIntoBody( tag );
		}

		function insertIntoBody( text ) {
			if ( editor ) {
				editor.replaceSelection( text );
				editor.focus();
				return;
			}

			bodyArea.setRangeText( text, bodyArea.selectionStart, bodyArea.selectionEnd, 'end' );
			bodyArea.focus();
			schedulePreview();
		}

		// Built through the DOM, so the URL and alt text come out properly escaped.
		function imageHtml( attachment ) {
			var size = ( attachment.sizes && ( attachment.sizes.large || attachment.sizes.full ) ) || attachment;
			var img = document.createElement( 'img' );

			img.setAttribute( 'src', size.url );
			img.setAttribute( 'alt', attachment.alt || attachment.title || '' );

			if ( size.width ) {
				img.setAttribute( 'width', String( Math.min( parseInt( size.width, 10 ), IMAGE_MAX_WIDTH ) ) );
			}

			img.setAttribute( 'style', 'display:block;max-width:100%;height:auto;border:0;' );

			return img.outerHTML;
		}

		function openMedia() {
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}

			if ( ! mediaFrame ) {
				mediaFrame = window.wp.media( {
					title: iftpFmEntries.i18n.emailMediaTitle,
					button: { text: iftpFmEntries.i18n.emailMediaButton },
					library: { type: 'image' },
					multiple: false,
				} );

				mediaFrame.on( 'select', function () {
					var attachment = mediaFrame.state().get( 'selection' ).first();

					if ( attachment ) {
						insertIntoBody( imageHtml( attachment.toJSON() ) );
					}
				} );
			}

			mediaFrame.open();
		}

		trigger.addEventListener( 'click', open );
		modal.querySelector( '[data-iftp-email-cancel]' ).addEventListener( 'click', close );

		if ( mediaBtn ) {
			mediaBtn.hidden = ! window.wp || ! window.wp.media;
			mediaBtn.addEventListener( 'click', openMedia );
		}

		modal.querySelector( '[data-iftp-email-overlay]' ).addEventListener( 'click', function () {
			if ( ! isDirty() ) {
				close();
			}
		} );

		// Only Escapes from inside my modal: the Media Library sits on top and handles its own.
		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! modal.hidden && modal.contains( event.target ) && ! isDirty() ) {
				close();
			}
		} );

		subjectInput.addEventListener( 'focus', function () {
			tagTarget = 'subject';
		} );
		bodyArea.addEventListener( 'focus', function () {
			tagTarget = 'body';
		} );
		subjectInput.addEventListener( 'input', schedulePreview );
		bodyArea.addEventListener( 'input', schedulePreview );

		modal.querySelectorAll( '[data-iftp-email-tag]' ).forEach( function ( tagBtn ) {
			function describe() {
				tagDesc.textContent = tagBtn.getAttribute( 'data-iftp-email-tag' ) + ' — ' + tagBtn.getAttribute( 'data-iftp-email-tag-help' );
			}

			function undescribe() {
				tagDesc.textContent = tagDescDefault;
			}

			tagBtn.addEventListener( 'click', function () {
				insertTag( tagBtn.getAttribute( 'data-iftp-email-tag' ) );
			} );
			tagBtn.addEventListener( 'mouseenter', describe );
			tagBtn.addEventListener( 'focus', describe );
			tagBtn.addEventListener( 'mouseleave', undescribe );
			tagBtn.addEventListener( 'blur', undescribe );
		} );

		modal.querySelector( '[data-iftp-email-reset]' ).addEventListener( 'click', function () {
			subjectInput.value = config.defaults.subject;
			setBody( config.defaults.body );
			errorEl.hidden = true;
			schedulePreview();
			showToast( iftpFmEntries.i18n.emailResetDone );
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			submitBtn.disabled = true;
			submitBtn.textContent = iftpFmEntries.i18n.emailSaving;
			errorEl.hidden = true;

			post( 'iftp_fm_save_payment_email', { subject: subjectInput.value, body: getBody() } )
				.then( function ( response ) {
					submitBtn.disabled = false;
					submitBtn.textContent = submitLabel;

					if ( ! response.success ) {
						showError( ( response.data && response.data.message ) || iftpFmEntries.i18n.emailSaveError );
						return;
					}

					// What was stored, after sanitizing, so reopening shows exactly what goes out.
					saved = { subject: response.data.subject, body: response.data.body };
					close();
					showToast( response.data.message );
				} )
				.catch( function () {
					submitBtn.disabled = false;
					submitBtn.textContent = submitLabel;
					showError( iftpFmEntries.i18n.emailSaveError );
				} );
		} );
	}

	// Fills the shared confirm dialog per call, in place of window.confirm().
	function confirmAction( title, message, confirmLabel, danger, onConfirm ) {
		var modal = document.querySelector( '[data-iftp-modal]' );

		if ( ! modal ) {
			onConfirm();
			return;
		}

		var overlay = modal.querySelector( '[data-iftp-modal-overlay]' );
		var cancelBtn = modal.querySelector( '[data-iftp-modal-cancel]' );
		var confirmBtn = modal.querySelector( '[data-iftp-modal-confirm]' );

		var returnFocusTo = document.activeElement;

		modal.querySelector( '[data-iftp-modal-title]' ).textContent = title;
		modal.querySelector( '[data-iftp-modal-message]' ).textContent = message;
		confirmBtn.textContent = confirmLabel;
		confirmBtn.classList.toggle( 'iftp-fm-modal-confirm--danger', !! danger );

		function close() {
			modal.hidden = true;
			confirmBtn.removeEventListener( 'click', onConfirmClick );
			cancelBtn.removeEventListener( 'click', close );
			overlay.removeEventListener( 'click', close );
			document.removeEventListener( 'keydown', onKeydown );
			releaseFocus( modal );

			if ( returnFocusTo && document.body.contains( returnFocusTo ) ) {
				returnFocusTo.focus();
			}
		}

		function onKeydown( event ) {
			if ( 'Escape' === event.key ) {
				close();
			}
		}

		function onConfirmClick() {
			close();
			onConfirm();
		}

		confirmBtn.addEventListener( 'click', onConfirmClick );
		cancelBtn.addEventListener( 'click', close );
		overlay.addEventListener( 'click', close );
		document.addEventListener( 'keydown', onKeydown );

		modal.hidden = false;
		trapFocus( modal );
		// Cancel first for destructive actions, so Enter doesn't delete by accident.
		( danger ? cancelBtn : confirmBtn ).focus();
	}

	function trapFocus( modal ) {
		releaseFocus( modal );

		modal.iftpTrap = function ( event ) {
			if ( 'Tab' !== event.key ) {
				return;
			}

			var focusable = Array.prototype.filter.call(
				modal.querySelectorAll( 'button, input, select, textarea, a[href]' ),
				function ( el ) {
					return ! el.disabled && null !== el.offsetParent;
				}
			);

			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		};

		modal.addEventListener( 'keydown', modal.iftpTrap );
	}

	function releaseFocus( modal ) {
		if ( modal.iftpTrap ) {
			modal.removeEventListener( 'keydown', modal.iftpTrap );
			modal.iftpTrap = null;
		}
	}

	var toastTimer = null;

	// A sticky toast stays up until the next one replaces it (bulk progress).
	function showToast( message, isError, sticky ) {
		var toast = document.querySelector( '[data-iftp-toast]' );

		if ( ! toast ) {
			return;
		}

		toast.querySelector( '[data-iftp-toast-message]' ).textContent = message;
		toast.classList.toggle( 'iftp-fm-toast--error', !! isError );
		toast.hidden = false;

		if ( toastTimer ) {
			clearTimeout( toastTimer );
			toastTimer = null;
		}

		if ( sticky ) {
			return;
		}

		toastTimer = setTimeout( function () {
			toast.hidden = true;
		}, 4000 );
	}

	function bindScrollButton( wrap ) {
		var btn = wrap.querySelector( '[data-iftp-scroll-btn]' );

		if ( ! btn ) {
			return;
		}

		var reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' );
		var queued = false;

		window.addEventListener(
			'scroll',
			function () {
				if ( queued ) {
					return;
				}

				queued = true;
				window.requestAnimationFrame( function () {
					queued = false;
					btn.classList.toggle( 'is-visible', window.scrollY > 400 );
				} );
			},
			{ passive: true }
		);

		btn.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' } );
		} );
	}

	// Delegated, since rows get swapped in by AJAX. Covers the Copy link action and the reference itself.
	function bindCopyLink( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-iftp-copy-link], [data-iftp-copy-ref]' );

			if ( ! btn ) {
				return;
			}

			var isRef = btn.hasAttribute( 'data-iftp-copy-ref' );
			var copiedClass = isRef ? 'iftp-fm-ref-copy--copied' : 'iftp-fm-row-action--copied';

			copyText( btn.getAttribute( isRef ? 'data-iftp-copy-ref' : 'data-iftp-copy-link' ), btn )
				.then( function () {
					btn.classList.add( copiedClass );

					if ( btn.iftpCopiedTimer ) {
						clearTimeout( btn.iftpCopiedTimer );
					}

					btn.iftpCopiedTimer = setTimeout( function () {
						btn.classList.remove( copiedClass );
						btn.iftpCopiedTimer = null;
					}, 2000 );

					showToast( isRef ? iftpFmEntries.i18n.copyRefSuccess : iftpFmEntries.i18n.copyLinkSuccess );
				} )
				.catch( function () {
					showToast( isRef ? iftpFmEntries.i18n.copyRefError : iftpFmEntries.i18n.copyLinkError, true );
				} );
		} );
	}

	// The details panel's "Send payment email" — confirm first, since it lands in the customer's inbox.
	function bindResendEmail( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-iftp-resend-email]' );

			if ( ! btn || btn.disabled ) {
				return;
			}

			confirmAction(
				iftpFmEntries.i18n.confirmResendTitle,
				iftpFmEntries.i18n.confirmResendMessage.replace( '%s', btn.getAttribute( 'data-iftp-resend-to' ) ),
				iftpFmEntries.i18n.confirmResendButton,
				false,
				function () {
					var label = btn.querySelector( '[data-iftp-resend-label]' );
					var idle = label.textContent;

					btn.disabled = true;
					label.textContent = iftpFmEntries.i18n.resendSending;

					post( 'iftp_fm_resend_payment_email', { ref: btn.getAttribute( 'data-iftp-resend-email' ) } )
						.then( function ( response ) {
							var message = response && response.data && response.data.message;

							showToast( message || iftpFmEntries.i18n.resendError, ! ( response && response.success ) );
						} )
						.catch( function () {
							showToast( iftpFmEntries.i18n.resendError, true );
						} )
						.then( function () {
							btn.disabled = false;
							label.textContent = idle;
						} );
				}
			);
		} );
	}

	function copyText( text, returnFocusTo ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return legacyCopy( text, returnFocusTo );
			} );
		}

		return legacyCopy( text, returnFocusTo );
	}

	// Plain-http admins have no Clipboard API, so I fall back to execCommand, then hand focus back.
	function legacyCopy( text, returnFocusTo ) {
		var textarea = document.createElement( 'textarea' );
		var copied = false;

		textarea.value = text;
		textarea.setAttribute( 'readonly', '' );
		textarea.style.position = 'fixed';
		textarea.style.top = '0';
		textarea.style.left = '-9999px';
		textarea.style.opacity = '0';
		document.body.appendChild( textarea );
		textarea.select();

		try {
			copied = document.execCommand( 'copy' );
		} catch ( e ) {
			copied = false;
		}

		textarea.remove();

		if ( returnFocusTo && document.body.contains( returnFocusTo ) ) {
			returnFocusTo.focus();
		}

		return copied ? Promise.resolve() : Promise.reject( new Error( 'copy failed' ) );
	}

	function bindColumnsMenu( wrap ) {
		var trigger = wrap.querySelector( '[data-iftp-columns-trigger]' );
		var panel = wrap.querySelector( '[data-iftp-columns-menu]' );

		if ( ! trigger || ! panel ) {
			return;
		}

		var toggles = Array.prototype.slice.call( panel.querySelectorAll( '[data-iftp-col-toggle]' ) );
		var list = panel.querySelector( '[data-iftp-col-list]' );
		var status = wrap.querySelector( '[data-iftp-col-status]' );
		var reset = panel.querySelector( '[data-iftp-col-reset]' );
		var DRAGGING = 'iftp-fm-columns-menu__item--dragging';
		var DROP_BEFORE = 'iftp-fm-columns-menu__item--drop-before';
		var DROP_AFTER = 'iftp-fm-columns-menu__item--drop-after';
		var saveTimer = null;
		var announceTimer = null;
		var dragging = null;

		function isOpen() {
			return ! panel.hidden;
		}

		function setOpen( open ) {
			panel.hidden = ! open;
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		function isInside( node ) {
			return !! node && ( trigger.contains( node ) || panel.contains( node ) );
		}

		function hiddenCols() {
			return toggles
				.filter( function ( input ) {
					return ! input.checked;
				} )
				.map( function ( input ) {
					return input.getAttribute( 'data-iftp-col-toggle' );
				} );
		}

		function items() {
			return list ? Array.prototype.slice.call( list.querySelectorAll( '[data-iftp-col-item]' ) ) : [];
		}

		function readOrder() {
			var order = items().map( function ( item ) {
				return item.getAttribute( 'data-iftp-col-item' );
			} );

			return order.length ? order : rootColumnOrder( wrap );
		}

		function save() {
			post( 'iftp_fm_save_columns', { hidden: hiddenCols(), order: readOrder() } )
				.then( function ( response ) {
					if ( ! response.success ) {
						showToast( ( response.data && response.data.message ) || iftpFmEntries.i18n.columnsSaveError, true );
					}
				} )
				.catch( function () {
					showToast( iftpFmEntries.i18n.columnsSaveError, true );
				} );
		}

		// I update the view right away; only the save waits.
		function scheduleSave() {
			if ( saveTimer ) {
				clearTimeout( saveTimer );
			}

			saveTimer = setTimeout( save, 400 );
		}

		function setEdge( btn, atEdge ) {
			if ( ! btn ) {
				return;
			}

			if ( atEdge ) {
				btn.setAttribute( 'aria-disabled', 'true' );
			} else {
				btn.removeAttribute( 'aria-disabled' );
			}
		}

		function syncMoveButtons() {
			var all = items();

			all.forEach( function ( item, i ) {
				setEdge( item.querySelector( '[data-iftp-col-move="up"]' ), 0 === i );
				setEdge( item.querySelector( '[data-iftp-col-move="down"]' ), all.length - 1 === i );
			} );
		}

		// Cleared first, so the same message twice in a row still gets spoken.
		function announce( message ) {
			if ( ! status || ! message ) {
				return;
			}

			status.textContent = '';

			if ( announceTimer ) {
				clearTimeout( announceTimer );
			}

			announceTimer = setTimeout( function () {
				status.textContent = message;
			}, 100 );
		}

		function commit( item ) {
			var order = readOrder();

			// A drop back where it started changes nothing, so there's nothing to save.
			if ( order.join( ' ' ) === rootColumnOrder( wrap ).join( ' ' ) ) {
				return;
			}

			var labelEl = item.querySelector( '.iftp-fm-columns-menu__option-label' );
			var slug = item.getAttribute( 'data-iftp-col-item' );

			wrap.setAttribute( 'data-iftp-col-order', order.join( ' ' ) );
			applyColumnOrder( wrap, order );
			syncMoveButtons();
			announce(
				( iftpFmEntries.i18n.columnMoved || '' )
					.replace( '%1$s', labelEl ? labelEl.textContent.trim() : slug )
					.replace( '%2$s', order.indexOf( slug ) + 1 )
					.replace( '%3$s', order.length )
			);
			scheduleSave();
		}

		function clearDropMarks() {
			items().forEach( function ( item ) {
				item.classList.remove( DROP_BEFORE, DROP_AFTER );
			} );
		}

		function markDrop( target, before ) {
			items().forEach( function ( item ) {
				item.classList.toggle( DROP_BEFORE, item === target && before );
				item.classList.toggle( DROP_AFTER, item === target && ! before );
			} );
		}

		// Text-selection drags can target a text node, which has no closest().
		function closestItem( node ) {
			var el = node && 1 !== node.nodeType ? node.parentElement : node;

			return el && el.closest ? el.closest( '[data-iftp-col-item]' ) : null;
		}

		trigger.addEventListener( 'click', function () {
			setOpen( ! isOpen() );
		} );

		panel.addEventListener( 'change', function ( event ) {
			if ( ! event.target.matches( '[data-iftp-col-toggle]' ) ) {
				return;
			}

			wrap.setAttribute( 'data-iftp-hidden-cols', hiddenCols().join( ' ' ) );
			scheduleSave();
		} );

		// I move the neighbour, not the item, so the focused button never leaves the DOM.
		panel.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-iftp-col-move]' );
			var item = btn ? btn.closest( '[data-iftp-col-item]' ) : null;
			var neighbour;

			if ( ! item || ! list || 'true' === btn.getAttribute( 'aria-disabled' ) ) {
				return;
			}

			if ( 'up' === btn.getAttribute( 'data-iftp-col-move' ) ) {
				neighbour = item.previousElementSibling;

				if ( neighbour ) {
					list.insertBefore( neighbour, item.nextElementSibling );
				}
			} else {
				neighbour = item.nextElementSibling;

				if ( neighbour ) {
					list.insertBefore( neighbour, item );
				}
			}

			commit( item );
			btn.focus();
		} );

		if ( list ) {
			list.addEventListener( 'dragstart', function ( event ) {
				var el = event.target && 1 === event.target.nodeType ? event.target : null;
				var handle = el ? el.closest( '[data-iftp-col-drag]' ) : null;
				var item = handle ? handle.closest( '[data-iftp-col-item]' ) : null;

				if ( ! item ) {
					return;
				}

				dragging = item;
				event.dataTransfer.effectAllowed = 'move';
				// Firefox won't start a drag without data. A custom type keeps the slug from dropping into the search field as text.
				event.dataTransfer.setData( 'application/x-iftp-fm-column', item.getAttribute( 'data-iftp-col-item' ) );

				if ( 'function' === typeof event.dataTransfer.setDragImage ) {
					event.dataTransfer.setDragImage( item, 16, item.offsetHeight / 2 );
				}

				// One tick later, so the drag ghost isn't the dimmed row.
				setTimeout( function () {
					if ( dragging === item ) {
						item.classList.add( DRAGGING );
					}
				}, 0 );
			} );

			list.addEventListener( 'dragover', function ( event ) {
				var target;
				var rect;

				// Files and drags from outside the list aren't mine.
				if ( ! dragging ) {
					return;
				}

				target = closestItem( event.target );

				if ( ! target || target === dragging ) {
					clearDropMarks();
					return;
				}

				event.preventDefault();
				event.dataTransfer.dropEffect = 'move';
				rect = target.getBoundingClientRect();
				markDrop( target, event.clientY < rect.top + rect.height / 2 );
			} );

			list.addEventListener( 'dragleave', function ( event ) {
				if ( ! list.contains( event.relatedTarget ) ) {
					clearDropMarks();
				}
			} );

			list.addEventListener( 'drop', function ( event ) {
				var item = dragging;
				var target;

				if ( ! item ) {
					return;
				}

				event.preventDefault();
				target = list.querySelector( '.' + DROP_BEFORE + ', .' + DROP_AFTER );

				if ( target && target !== item ) {
					list.insertBefore( item, target.classList.contains( DROP_BEFORE ) ? target : target.nextElementSibling );
				}

				clearDropMarks();

				if ( target ) {
					commit( item );
				}
			} );

			// Covers Escape-cancelled drags and drops outside the popover too.
			list.addEventListener( 'dragend', function () {
				if ( dragging ) {
					dragging.classList.remove( DRAGGING );
				}

				clearDropMarks();
				dragging = null;
			} );
		}

		if ( reset ) {
			reset.addEventListener( 'click', function () {
				var defaults = Array.isArray( iftpFmEntries.defaultColumnOrder ) ? iftpFmEntries.defaultColumnOrder : readOrder();
				var bySlug = {};

				items().forEach( function ( item ) {
					bySlug[ item.getAttribute( 'data-iftp-col-item' ) ] = item;
				} );

				defaults.forEach( function ( slug ) {
					if ( bySlug[ slug ] ) {
						list.appendChild( bySlug[ slug ] );
					}
				} );

				toggles.forEach( function ( input ) {
					input.checked = true;
				} );

				wrap.setAttribute( 'data-iftp-hidden-cols', '' );
				wrap.setAttribute( 'data-iftp-col-order', defaults.join( ' ' ) );
				applyColumnOrder( wrap, defaults );
				syncMoveButtons();
				announce( iftpFmEntries.i18n.columnsReset );
				scheduleSave();
			} );
		}

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && isOpen() ) {
				setOpen( false );
				trigger.focus();
			}
		} );

		document.addEventListener( 'click', function ( event ) {
			if ( isOpen() && ! isInside( event.target ) ) {
				setOpen( false );
			}
		} );

		// A null relatedTarget is a click on nothing focusable; the click handler covers that.
		function onFocusOut( event ) {
			if ( isOpen() && event.relatedTarget && ! isInside( event.relatedTarget ) ) {
				setOpen( false );
			}
		}

		trigger.addEventListener( 'focusout', onFocusOut );
		panel.addEventListener( 'focusout', onFocusOut );
	}

	// An inline disclosure, not a popover: only the toggle and Escape close it.
	function bindFilterPanel( wrap ) {
		var toggle = wrap.querySelector( '[data-iftp-filters-toggle]' );
		var panel = wrap.querySelector( '[data-iftp-filter-panel]' );
		var form = wrap.querySelector( '#iftp-fm-filter-form' );

		if ( ! toggle || ! panel || ! form ) {
			return;
		}

		var firstField = panel.querySelector( '#iftp-fm-form-filter' );
		var dateFrom = panel.querySelector( '#iftp-fm-date-from' );
		var dateTo = panel.querySelector( '#iftp-fm-date-to' );
		var PANEL_ITEM = 'undefined' !== typeof iftpFmEntriesStore && iftpFmEntriesStore.key ? iftpFmEntriesStore.key + ':panel' : '';

		function setOpen( open ) {
			panel.hidden = ! open;
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		}

		// Remembered for the tab's session; Escape counts as closing it too.
		function persistOpen( open ) {
			if ( ! PANEL_ITEM ) {
				return;
			}

			try {
				if ( open ) {
					sessionStorage.setItem( PANEL_ITEM, '1' );
				} else {
					sessionStorage.removeItem( PANEL_ITEM );
				}
			} catch ( e ) {
				// No storage, so it just starts closed next time.
			}
		}

		function wasOpen() {
			try {
				return !! PANEL_ITEM && '1' === sessionStorage.getItem( PANEL_ITEM );
			} catch ( e ) {
				return false;
			}
		}

		// No focus move here: the admin didn't ask for it on this load.
		if ( wasOpen() ) {
			setOpen( true );
		}

		toggle.addEventListener( 'click', function () {
			var open = panel.hidden;

			setOpen( open );
			persistOpen( open );

			// It sits after Columns and Export in tab order, so I take the admin straight to it.
			if ( open && firstField ) {
				firstField.focus();
			}
		} );

		panel.addEventListener( 'keydown', function ( event ) {
			if ( 'Escape' === event.key && ! panel.hidden ) {
				setOpen( false );
				persistOpen( false );
				toggle.focus();
			}
		} );

		// Submitting the form (not following the link) keeps unapplied form/method/amount edits.
		panel.addEventListener( 'click', function ( event ) {
			var preset = event.target.closest( '[data-iftp-date-preset]' );

			if ( ! preset || ! dateFrom || ! dateTo ) {
				return;
			}

			// Ctrl/Cmd/Shift-click still opens the link the browser's way.
			if ( event.ctrlKey || event.metaKey || event.shiftKey || event.altKey ) {
				return;
			}

			event.preventDefault();
			dateFrom.value = preset.getAttribute( 'data-iftp-date-from' ) || '';
			dateTo.value = preset.getAttribute( 'data-iftp-date-to' ) || '';
			submitForm( form );
		} );
	}

	// Arrowing a closed select fires change on Windows, so I don't reload on those (WCAG 3.2.2).
	function bindPerPage( wrap ) {
		var form = wrap.querySelector( '[data-iftp-per-page-form]' );
		var select = form ? form.querySelector( '[data-iftp-per-page]' ) : null;
		var go = form ? form.querySelector( '[data-iftp-per-page-submit]' ) : null;
		var custom = form ? form.querySelector( '[data-iftp-per-page-custom]' ) : null;
		var customInput = form ? form.querySelector( '#iftp-fm-per-page-custom' ) : null;
		var NAV_KEYS = [ 'ArrowUp', 'ArrowDown', 'Home', 'End', 'PageUp', 'PageDown' ];
		var keyNav = false;

		if ( ! select ) {
			return;
		}

		// Off "custom", I disable the number box too, so a stale bad value can't block a preset's submit.
		function syncCustom() {
			var isCustom = 'custom' === select.value;

			if ( custom ) {
				custom.hidden = ! isCustom;
			}

			if ( customInput ) {
				customInput.disabled = ! isCustom;
				customInput.required = isCustom;
			}

			if ( go ) {
				go.hidden = ! isCustom;
			}

			return isCustom;
		}

		function focusCustom() {
			if ( customInput ) {
				customInput.focus();
				customInput.select();
			}
		}

		syncCustom();

		select.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key ) {
				event.preventDefault();

				if ( 'custom' === select.value ) {
					focusCustom();
				} else {
					submitForm( form );
				}

				return;
			}

			// Type-ahead letters change the value too; Space and Alt+Arrow just open the list.
			keyNav =
				! event.altKey &&
				( -1 !== NAV_KEYS.indexOf( event.key ) ||
					( 1 === event.key.length && ' ' !== event.key && ! event.ctrlKey && ! event.metaKey ) );
		} );

		select.addEventListener( 'pointerdown', function () {
			keyNav = false;
		} );

		select.addEventListener( 'change', function () {
			// While arrowing I only reveal the box; jumping focus would strand the admin mid-list.
			if ( syncCustom() ) {
				if ( ! keyNav ) {
					focusCustom();
				}

				return;
			}

			if ( keyNav ) {
				if ( go ) {
					go.hidden = false;
				}

				return;
			}

			submitForm( form );
		} );
	}
} )();
