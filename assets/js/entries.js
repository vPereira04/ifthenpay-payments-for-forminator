/**
 * Payment Entries admin dashboard — drives the parts of the screen that
 * don't need a full page reload: bulk select/status-change/delete, the
 * "+ New Payment" popup, row details toggling, a confirm dialog + toast in
 * place of window.confirm()/alert(), a scroll-to-top button, the
 * copy-link row action, the columns popover (show/hide and reorder), the
 * filter panel disclosure, the per-page auto-submit, and "Export selected".
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
		bindCopyLink( wrap );
		bindColumnsMenu( wrap );
		bindFilterPanel( wrap );
		bindPerPage( wrap );

		restoreSelectionState( wrap );
	} );

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

	/**
	 * Every AJAX action ends by swapping in a freshly rendered tbody +
	 * pagination + counts for the browser's current filtered/sorted/
	 * paginated view, so the screen reflects the change without a reload.
	 */
	function applyViewResponse( wrap, data ) {
		var body = wrap.querySelector( '[data-iftp-entries-body]' );
		var pagination = wrap.querySelector( '[data-iftp-pagination]' );
		var totalCount = wrap.querySelector( '[data-iftp-total-count]' );
		var summary = wrap.querySelector( '[data-iftp-summary]' );

		if ( body && 'string' === typeof data.rowsHtml ) {
			body.innerHTML = data.rowsHtml;
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
				} else if ( countEl ) {
					// The server leaves out statuses with no rows, so I zero those tabs.
					countEl.textContent = 0;
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
		params[ prefix + 'pay_method' ] = f.pay_method || '';
		params[ prefix + 'amount_min' ] = f.amount_min || '';
		params[ prefix + 'amount_max' ] = f.amount_max || '';
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
				toggle.setAttribute( 'aria-expanded', target.hidden ? 'false' : 'true' );
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
			bulkCount.textContent = iftpFmEntries.i18n.bulkSelectedCount.replace( '%s', count );
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

	/**
	 * Posts the selected refs to the admin-post export handler through a
	 * throwaway form, so the browser handles the CSV download itself.
	 *
	 * @param {string[]} refs
	 */
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
		refs.forEach( function ( ref ) {
			addField( 'refs[]', ref );
		} );

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

		var reducedMotion = window.matchMedia( '(prefers-reduced-motion: reduce)' );

		window.addEventListener( 'scroll', function () {
			btn.classList.toggle( 'is-visible', window.scrollY > 400 );
		} );

		btn.addEventListener( 'click', function () {
			window.scrollTo( { top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' } );
		} );
	}

	// Delegated, since rows get swapped in by AJAX.
	function bindCopyLink( wrap ) {
		wrap.addEventListener( 'click', function ( event ) {
			var btn = event.target.closest( '[data-iftp-copy-link]' );

			if ( ! btn ) {
				return;
			}

			copyText( btn.getAttribute( 'data-iftp-copy-link' ), btn )
				.then( function () {
					btn.classList.add( 'iftp-fm-row-action--copied' );

					if ( btn.iftpCopiedTimer ) {
						clearTimeout( btn.iftpCopiedTimer );
					}

					btn.iftpCopiedTimer = setTimeout( function () {
						btn.classList.remove( 'iftp-fm-row-action--copied' );
						btn.iftpCopiedTimer = null;
					}, 2000 );

					showToast( iftpFmEntries.i18n.copyLinkSuccess );
				} )
				.catch( function () {
					showToast( iftpFmEntries.i18n.copyLinkError, true );
				} );
		} );
	}

	/**
	 * @param {string}  text
	 * @param {Element} returnFocusTo Refocused after the textarea fallback.
	 * @return {Promise} Resolves once copied.
	 */
	function copyText( text, returnFocusTo ) {
		if ( navigator.clipboard && window.isSecureContext ) {
			return navigator.clipboard.writeText( text ).catch( function () {
				return legacyCopy( text, returnFocusTo );
			} );
		}

		return legacyCopy( text, returnFocusTo );
	}

	// Plain-http admins have no Clipboard API, so I fall back to execCommand.
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
