/**
 * Payment Entries admin dashboard — drives the parts of the screen that
 * don't need a full page reload: bulk select/status-change/delete, the
 * "+ New Payment" popup, row details toggling, a confirm dialog + toast in
 * place of window.confirm()/alert(), and a scroll-to-top button.
 *
 * Sort/filter/pagination stay plain GET links/form submits (see
 * `Admin\PaymentEntriesPage::filtered_url()`) — only the actions below ever
 * touch admin-ajax.php.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpFmEntries ) {
		return;
	}

	// Selected refs live here, not just as checked checkboxes in the DOM —
	// pagination is a plain link (full page reload), so a Set backed by
	// sessionStorage is what lets a selection make it from one page to the
	// next instead of being wiped every time the admin turns the page.
	var SELECTION_STORAGE_KEY = 'iftpFmEntriesSelectedRefs';
	var selectedRefsSet = new Set( loadPersistedSelection() );

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
		bindScrollButton( wrap );

		restoreSelectionState( wrap );
	} );

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

	/**
	 * Every AJAX action ends by swapping in a freshly rendered tbody +
	 * pagination + counts for the browser's current filtered/sorted/
	 * paginated view, so the screen reflects the change without a reload.
	 */
	function applyViewResponse( wrap, data ) {
		var body = wrap.querySelector( '[data-iftp-entries-body]' );
		var pagination = wrap.querySelector( '[data-iftp-pagination]' );
		var totalCount = wrap.querySelector( '[data-iftp-total-count]' );

		if ( body && 'string' === typeof data.rowsHtml ) {
			body.innerHTML = data.rowsHtml;
		}

		if ( pagination && 'string' === typeof data.paginationHtml ) {
			pagination.innerHTML = data.paginationHtml;
		}

		if ( totalCount && 'string' === typeof data.totalLabel ) {
			totalCount.textContent = data.totalLabel;
		}

		if ( totalCount && 'number' === typeof data.total ) {
			totalCount.setAttribute( 'data-iftp-total', data.total );
		}

		if ( data.counts ) {
			wrap.querySelectorAll( '.iftp-fm-status-tab' ).forEach( function ( tab ) {
				var url = new URL( tab.getAttribute( 'href' ), window.location.href );
				var slug = url.searchParams.get( 'status' ) || '';
				var countEl = tab.querySelector( '.count' );

				if ( countEl && Object.prototype.hasOwnProperty.call( data.counts, slug ) ) {
					countEl.textContent = data.counts[ slug ];
				} else if ( countEl && '' === slug ) {
					var total = 0;
					Object.keys( data.counts ).forEach( function ( key ) {
						total += parseInt( data.counts[ key ], 10 ) || 0;
					} );
					countEl.textContent = total;
				}
			} );
		}

		// Re-check whichever of the freshly rendered rows are still part of
		// the (possibly cross-page) selection, rather than clearing it —
		// this same response also lands after a plain filter-preserving
		// refresh, not only after the selection's own bulk action.
		restoreSelectionState( wrap );
	}

	/**
	 * The current view's filter/sort/pagination state, read straight off
	 * the page's own URL — every AJAX handler needs this to know which
	 * page of results to hand back.
	 *
	 * @param {string} prefix '' or 'view_' — see `PaymentEntriesPage::view_state_from_request()`.
	 */
	function currentViewParams( prefix ) {
		var f = iftpFmEntries.currentFilters;
		var params = {};

		params[ prefix + 's' ] = f.s;
		// Always 'view_status', never just prefix + 'status' — with an
		// empty prefix that would collide with the bulk status-change
		// request's own 'status' field (the *target* status), which is a
		// different thing entirely. See view_state_from_request() in PHP.
		params.view_status = f.status;
		params[ prefix + 'form_id' ] = f.form_id;
		params[ prefix + 'date_from' ] = f.date_from;
		params[ prefix + 'date_to' ] = f.date_to;
		params[ prefix + 'orderby' ] = f.orderby;
		params[ prefix + 'order' ] = f.order;
		params[ prefix + 'per_page' ] = f.per_page;
		params[ prefix + 'paged' ] = f.paged;

		return params;
	}

	function bindDetailsToggle( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			var toggle = event.target.closest( '.iftp-fm-details-toggle' );

			if ( ! toggle ) {
				return;
			}

			var target = document.getElementById( toggle.getAttribute( 'data-details-target' ) );

			if ( target ) {
				target.hidden = ! target.hidden;
			}
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

	function persistSelection() {
		try {
			sessionStorage.setItem( SELECTION_STORAGE_KEY, JSON.stringify( Array.from( selectedRefsSet ) ) );
		} catch ( e ) {
			// sessionStorage unavailable (private browsing, storage full,
			// etc.) — the selection just won't survive turning the page.
		}
	}

	function visibleRowCheckboxes( wrap ) {
		return Array.prototype.slice.call( wrap.querySelectorAll( '[data-iftp-row-check]' ) );
	}

	/**
	 * Drops the given refs out of the selection without touching the rest
	 * of it — used once an action has actually consumed them (deleted them,
	 * or changed their status), so a still-in-progress cross-page selection
	 * elsewhere isn't wiped by an unrelated single-row delete.
	 *
	 * @param {string[]} refs
	 */
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

	/**
	 * Syncs every visible row checkbox (and the header "select all") to
	 * match selectedRefsSet, then refreshes the bulk-actions bar and the
	 * footer's selection summary. Called on load and after any re-render,
	 * since freshly inserted rows start unchecked regardless of what's
	 * already in the set.
	 */
	function restoreSelectionState( wrap ) {
		var checkboxes = visibleRowCheckboxes( wrap );

		checkboxes.forEach( function ( checkbox ) {
			checkbox.checked = selectedRefsSet.has( checkbox.value );
		} );

		var selectAll = wrap.querySelector( '[data-iftp-select-all]' );

		if ( selectAll ) {
			selectAll.checked = checkboxes.length > 0 && checkboxes.every( function ( checkbox ) {
				return checkbox.checked;
			} );
		}

		refreshSelectionUI( wrap );
	}

	/**
	 * Empties the whole selection, including refs selected on other pages —
	 * the footer's "Cancel" link, and every bulk action once it's applied.
	 */
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
						selectedRefsSet.add( checkbox.value );
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
				selectedRefsSet.add( event.target.value );
			} else {
				selectedRefsSet.delete( event.target.value );
			}

			persistSelection();

			var selectAllBox = wrap.querySelector( '[data-iftp-select-all]' );

			if ( selectAllBox ) {
				var checkboxes = visibleRowCheckboxes( wrap );
				selectAllBox.checked = checkboxes.length > 0 && checkboxes.every( function ( checkbox ) {
					return checkbox.checked;
				} );
			}

			refreshSelectionUI( wrap );
		} );
	}

	function bindSelectionCancel( wrap ) {
		var cancelBtn = wrap.querySelector( '[data-iftp-selection-cancel]' );

		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', function () {
				clearSelection( wrap );
			} );
		}
	}

	/**
	 * Shows/hides the header bulk-actions bar and swaps the footer between
	 * its normal "1 - 10 out of 17" range label and the "10 selected out of
	 * 17 | Cancel" summary, depending on whether anything is selected —
	 * across every page, not just the one currently on screen.
	 */
	function refreshSelectionUI( wrap ) {
		var bar = wrap.querySelector( '[data-iftp-bulk-actions]' );
		var rangeLabel = wrap.querySelector( '[data-iftp-total-count]' );
		var selectionSummary = wrap.querySelector( '[data-iftp-selection-summary]' );
		var selectionText = wrap.querySelector( '[data-iftp-selection-text]' );
		var count = selectedRefsSet.size;
		var hasSelection = count > 0;
		var total = rangeLabel ? parseInt( rangeLabel.getAttribute( 'data-iftp-total' ) || '0', 10 ) : 0;

		if ( bar ) {
			bar.hidden = ! hasSelection;
		}

		if ( rangeLabel ) {
			rangeLabel.hidden = hasSelection;
		}

		if ( selectionSummary ) {
			selectionSummary.hidden = ! hasSelection;
		}

		if ( selectionText ) {
			selectionText.textContent = iftpFmEntries.i18n.selectedOfTotal
				.replace( '%1$s', count )
				.replace( '%2$s', total );
		}
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
		post( 'iftp_fm_delete_entries', Object.assign( { refs: refs }, currentViewParams( '' ) ) )
			.then( function ( response ) {
				if ( ! response.success ) {
					showToast( ( response.data && response.data.message ) || iftpFmEntries.i18n.deleteError, true );
					return;
				}

				removeRefsFromSelection( refs );
				applyViewResponse( wrap, response.data );
				showToast( iftpFmEntries.i18n.deleteToastSuccess );
			} )
			.catch( function () {
				showToast( iftpFmEntries.i18n.deleteError, true );
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

			if ( '' === value || 0 === refs.length ) {
				return;
			}

			if ( 'delete' === value ) {
				confirmAction(
					iftpFmEntries.i18n.confirmDeleteTitle,
					iftpFmEntries.i18n.confirmDeleteMessage,
					iftpFmEntries.i18n.confirmDeleteButton,
					true,
					function () {
						deleteEntries( wrap, refs );
						select.value = '';
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
					updateStatus( wrap, refs, value );
					select.value = '';
				}
			);
		} );
	}

	function updateStatus( wrap, refs, status ) {
		post( 'iftp_fm_update_status', Object.assign( { refs: refs, status: status }, currentViewParams( '' ) ) )
			.then( function ( response ) {
				if ( ! response.success ) {
					showToast( ( response.data && response.data.message ) || iftpFmEntries.i18n.updateStatusError, true );
					return;
				}

				removeRefsFromSelection( refs );
				applyViewResponse( wrap, response.data );
				showToast( iftpFmEntries.i18n.statusToastSuccess );
			} )
			.catch( function () {
				showToast( iftpFmEntries.i18n.updateStatusError, true );
			} );
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
						errorEl.textContent = ( response.data && response.data.message ) || iftpFmEntries.i18n.newEntryError;
						errorEl.hidden = false;
						return;
					}

					applyViewResponse( wrap, response.data );
					showToast( iftpFmEntries.i18n.newEntryToastSuccess );
					close();
				} )
				.catch( function () {
					submitBtn.disabled = false;
					submitBtn.textContent = submitLabel;
					errorEl.textContent = iftpFmEntries.i18n.newEntryError;
					errorEl.hidden = false;
				} );
		} );
	}

	/**
	 * The one reusable confirm dialog (`render_confirm_modal()`), filled in
	 * per call — in place of window.confirm().
	 */
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

	/**
	 * Keeps Tab/Shift+Tab cycling inside an open modal.
	 *
	 * @param {Element} modal The open modal.
	 */
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

	function showToast( message, isError ) {
		var toast = document.querySelector( '[data-iftp-toast]' );

		if ( ! toast ) {
			return;
		}

		toast.querySelector( '[data-iftp-toast-message]' ).textContent = message;
		toast.classList.toggle( 'iftp-fm-toast--error', !! isError );
		toast.hidden = false;

		if ( toastTimer ) {
			clearTimeout( toastTimer );
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

		window.addEventListener( 'scroll', function () {
			btn.classList.toggle( 'is-visible', window.scrollY > 400 );
		} );

		btn.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: 'smooth' } );
		} );
	}
} )();
