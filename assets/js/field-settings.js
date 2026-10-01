// Forminator's own field-settings modal can't render for our field, so I catch the click on its
// canvas block and open our popup instead.
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpFmFieldSettings ) {
		return;
	}

	var modal = null;
	var currentFieldSlug = null;
	var returnFocusTo = null;
	var otherFields = [];
	var conditionRowId = 0;
	var loadRequest = 0;

	// Capture phase, so I run before Forminator's bubble-phase React onClick and can stop its broken modal.
	document.addEventListener(
		'click',
		function ( e ) {
			var info = e.target.closest( '.fui-builder-field-block[data-field="ifthenpay"] .fui-field--info' );

			if ( ! info ) {
				return;
			}

			var block = info.closest( '[id^="field-"]' );
			var fieldSlug = block ? block.id.replace( /^field-/, '' ) : '';

			if ( ! fieldSlug ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();

			openModal( fieldSlug );
		},
		true
	);

	// Forminator's builder takes field icons from its own client-side registry, so ours renders a bare <i>.
	// I set the class myself and keep watching, because React re-renders both spots.
	var FIELD_ICON_CLASS = 'sui-icon forminator-icon-ifthenpay';

	function applyFieldIcon() {
		var canvasIcon = document.querySelector( '.fui-builder-field-block[data-field="ifthenpay"] .fui-field--icon i' );

		if ( canvasIcon && FIELD_ICON_CLASS !== canvasIcon.className ) {
			canvasIcon.className = FIELD_ICON_CLASS;
		}

		var paletteIcon = document.querySelector( 'label[for="forminator-form-field-ifthenpay"] i' );

		if ( paletteIcon && FIELD_ICON_CLASS !== paletteIcon.className ) {
			paletteIcon.className = FIELD_ICON_CLASS;
		}
	}

	applyFieldIcon();
	new MutationObserver( applyFieldIcon ).observe( document.body, { childList: true, subtree: true } );

	// These radio groups are styled as SUI pill tabs. Setting .checked from code does not move the
	// wrapper's .active class the way a real click does, so I sync it by hand.
	function getRadioValue( name ) {
		var checked = document.querySelector( 'input[name="' + name + '"]:checked' );

		return checked ? checked.value : '';
	}

	function setRadioValue( name, value ) {
		document.querySelectorAll( 'input[name="' + name + '"]' ).forEach( function ( radio ) {
			radio.checked = radio.value === value;
		} );

		syncToggleTabsActive( name );
	}

	function syncToggleTabsActive( name ) {
		document.querySelectorAll( 'input[name="' + name + '"]' ).forEach( function ( radio ) {
			var tabItem = radio.closest( '.sui-tab-item' );

			if ( tabItem ) {
				tabItem.classList.toggle( 'active', radio.checked );
			}
		} );
	}

	function getModal() {
		if ( ! modal ) {
			modal = document.getElementById( 'iftp-fm-field-modal-wrap' );
		}

		return modal;
	}

	function openModal( fieldSlug ) {
		var wrap = getModal();

		if ( ! wrap ) {
			return;
		}

		currentFieldSlug = fieldSlug;
		returnFocusTo = document.activeElement;
		setMessage( '' );
		selectTab( 'settings' );
		wrap.classList.add( 'iftp-fm-active' );
		document.getElementById( 'iftp-fm-field-modal-close' ).focus();

		// Dimmed, with Apply locked, until this field's settings arrive: otherwise the previous field's
		// values could show up here, or be saved onto this field.
		var body = wrap.querySelector( '.sui-box-body' );
		var saveButton = document.getElementById( 'iftp-fm-field-modal-save' );
		var request = ++loadRequest;

		body.setAttribute( 'aria-busy', 'true' );
		saveButton.disabled = true;

		var tag = document.getElementById( 'iftp-fm-field-modal-tag' );

		if ( tag ) {
			tag.textContent = '{' + fieldSlug + '}';
		}

		post( 'iftp_fm_get_field_settings', { field_slug: fieldSlug } )
			.then( function ( response ) {
				// A slower reply for a field opened before this one.
				if ( request !== loadRequest ) {
					return;
				}

				if ( ! response.success ) {
					setMessage( ( response.data && response.data.message ) || iftpFmFieldSettings.i18n.unsaved );
					return;
				}

				populateForm( response.data );
				saveButton.disabled = false;
			} )
			.catch( function () {
				if ( request === loadRequest ) {
					setMessage( iftpFmFieldSettings.i18n.error );
				}
			} )
			.finally( function () {
				if ( request === loadRequest ) {
					body.removeAttribute( 'aria-busy' );
				}
			} );
	}

	function selectTab( name ) {
		document.querySelectorAll( '.iftp-fm-field-tab' ).forEach( function ( tab ) {
			var isActive = name === tab.getAttribute( 'data-tab' );
			tab.classList.toggle( 'iftp-fm-field-tab--active', isActive );
			tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			tab.setAttribute( 'tabindex', isActive ? '0' : '-1' );
		} );

		document.querySelectorAll( '.iftp-fm-field-tab-panel' ).forEach( function ( panel ) {
			panel.hidden = name !== panel.getAttribute( 'data-tab-panel' );
		} );
	}

	function closeModal() {
		var wrap = getModal();

		if ( wrap ) {
			wrap.classList.remove( 'iftp-fm-active' );
		}

		currentFieldSlug = null;

		if ( returnFocusTo && document.body.contains( returnFocusTo ) ) {
			returnFocusTo.focus();
		}

		returnFocusTo = null;
	}

	function populateForm( data ) {
		var isVariable = 'variable' === data.amount_type;

		setRadioValue( 'iftp_fm_field_amount_type', isVariable ? 'variable' : 'fixed' );
		document.getElementById( 'iftp-fm-field-amount' ).value = data.amount || '0';
		document.getElementById( 'iftp-fm-field-label' ).value = data.checkout_label || iftpFmFieldSettings.i18n.defaultLabel;

		otherFields = data.other_fields || [];

		var select = document.getElementById( 'iftp-fm-field-variable' );
		select.innerHTML = '';

		var placeholder = document.createElement( 'option' );
		placeholder.value = '';
		placeholder.textContent = iftpFmFieldSettings.i18n.selectField;
		select.appendChild( placeholder );

		otherFields.forEach( function ( field ) {
			var option = document.createElement( 'option' );
			option.value = field.slug;
			option.textContent = field.label + ' (' + field.slug + ')';
			select.appendChild( option );
		} );

		select.value = data.variable || '';

		toggleAmountRows( isVariable );

		document.getElementById( 'iftp-fm-field-label-text' ).value = data.field_label || '';
		document.getElementById( 'iftp-fm-field-description' ).value = data.description || '';
		document.getElementById( 'iftp-fm-field-description-placement' ).value = data.description_placement || 'below';
		document.getElementById( 'iftp-fm-field-css-class' ).value = data.css_class || '';

		document.getElementById( 'iftp-fm-field-hide-icon' ).checked = '1' === data.hide_icon;
		document.getElementById( 'iftp-fm-field-hide-methods' ).checked = '1' === data.hide_methods;

		setRadioValue( 'iftp_fm_field_condition_action', data.condition_action || 'show' );
		setRadioValue( 'iftp_fm_field_condition_rule', data.condition_rule || 'all' );

		var conditions = data.conditions || [];
		var list = document.getElementById( 'iftp-fm-field-conditions-list' );
		list.innerHTML = '';
		conditionRowId = 0;

		if ( conditions.length ) {
			conditions.forEach( addConditionRow );
		} else {
			addConditionRow();
		}

		toggleConditionalBody( conditions.length > 0 );
		document.getElementById( 'iftp-fm-field-conditional-enabled' ).checked = conditions.length > 0;
	}

	// Apply ignores the rows while this is off, so I lock them. The body is a <fieldset>, so rows added later are covered too.
	function toggleConditionalBody( enabled ) {
		document.getElementById( 'iftp-fm-field-conditional-body' ).disabled = ! enabled;
	}

	// One field/operator/value row, saved as conditions[i][element_id|rule|value]: the shape Forminator's
	// own front.condition.js evaluates.
	function addConditionRow( condition ) {
		condition = condition || {};

		var rowId = 'iftp-fm-condition-row-' + ( conditionRowId++ );
		var row = document.createElement( 'div' );
		row.className = 'iftp-fm-condition-row';
		row.id = rowId;

		var fieldSelect = document.createElement( 'select' );
		fieldSelect.className = 'iftp-fm-select';
		fieldSelect.setAttribute( 'data-condition-field', '' );

		otherFields.forEach( function ( field ) {
			var option = document.createElement( 'option' );
			option.value = field.slug;
			option.textContent = field.label + ' (' + field.slug + ')';
			fieldSelect.appendChild( option );
		} );

		fieldSelect.value = condition.element_id || '';

		var operatorSelect = document.createElement( 'select' );
		operatorSelect.className = 'iftp-fm-select';
		operatorSelect.setAttribute( 'data-condition-operator', '' );

		Object.keys( iftpFmFieldSettings.i18n.conditionOperators ).forEach( function ( operator ) {
			var option = document.createElement( 'option' );
			option.value = operator;
			option.textContent = iftpFmFieldSettings.i18n.conditionOperators[ operator ];
			operatorSelect.appendChild( option );
		} );

		operatorSelect.value = condition.rule || 'is';

		var valueInput = document.createElement( 'input' );
		valueInput.type = 'text';
		valueInput.className = 'sui-form-control';
		valueInput.setAttribute( 'data-condition-value', '' );
		valueInput.value = condition.value || '';

		var removeButton = document.createElement( 'button' );
		removeButton.type = 'button';
		removeButton.className = 'iftp-fm-condition-remove';
		removeButton.setAttribute( 'aria-label', iftpFmFieldSettings.i18n.removeCondition );
		removeButton.textContent = '×';
		removeButton.addEventListener( 'click', function () {
			row.remove();
		} );

		row.appendChild( fieldSelect );
		row.appendChild( operatorSelect );
		row.appendChild( valueInput );
		row.appendChild( removeButton );

		document.getElementById( 'iftp-fm-field-conditions-list' ).appendChild( row );
	}

	function collectConditions() {
		var rows = document.querySelectorAll( '#iftp-fm-field-conditions-list .iftp-fm-condition-row' );
		var conditions = [];

		rows.forEach( function ( row ) {
			var elementId = row.querySelector( '[data-condition-field]' ).value;

			if ( ! elementId ) {
				return;
			}

			conditions.push( {
				element_id: elementId,
				rule: row.querySelector( '[data-condition-operator]' ).value,
				value: row.querySelector( '[data-condition-value]' ).value,
			} );
		} );

		return conditions;
	}

	function toggleAmountRows( isVariable ) {
		document.getElementById( 'iftp-fm-field-amount-row' ).classList.toggle( 'iftp-fm-hidden', isVariable );
		document.getElementById( 'iftp-fm-field-variable-row' ).classList.toggle( 'iftp-fm-hidden', ! isVariable );
	}

	function setMessage( message ) {
		var el = document.getElementById( 'iftp-fm-field-modal-message' );

		if ( el ) {
			el.textContent = message;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = getModal();

		if ( ! wrap ) {
			return;
		}

		document.getElementById( 'iftp-fm-field-modal-cancel' ).addEventListener( 'click', closeModal );
		document.getElementById( 'iftp-fm-field-modal-close' ).addEventListener( 'click', closeModal );

		wrap.addEventListener( 'click', function ( e ) {
			if ( e.target === wrap ) {
				closeModal();
			}
		} );

		document.addEventListener( 'keydown', function ( e ) {
			if ( 'Escape' === e.key && wrap.classList.contains( 'iftp-fm-active' ) ) {
				closeModal();
			}
		} );

		[ 'iftp-fm-field-amount-type-fixed', 'iftp-fm-field-amount-type-variable' ].forEach( function ( id ) {
			document.getElementById( id ).addEventListener( 'change', function () {
				toggleAmountRows( 'iftp-fm-field-amount-type-variable' === id );
			} );
		} );

		document.querySelectorAll( '.iftp-fm-toggle-tabs input[type="radio"]' ).forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				syncToggleTabsActive( radio.name );
			} );
		} );

		var tabs = Array.prototype.slice.call( document.querySelectorAll( '.iftp-fm-field-tab' ) );

		tabs.forEach( function ( tab, index ) {
			tab.addEventListener( 'click', function () {
				selectTab( tab.getAttribute( 'data-tab' ) );
			} );

			tab.addEventListener( 'keydown', function ( e ) {
				var next = null;

				if ( 'ArrowRight' === e.key ) {
					next = tabs[ ( index + 1 ) % tabs.length ];
				} else if ( 'ArrowLeft' === e.key ) {
					next = tabs[ ( index - 1 + tabs.length ) % tabs.length ];
				} else if ( 'Home' === e.key ) {
					next = tabs[ 0 ];
				} else if ( 'End' === e.key ) {
					next = tabs[ tabs.length - 1 ];
				}

				if ( next ) {
					e.preventDefault();
					selectTab( next.getAttribute( 'data-tab' ) );
					next.focus();
				}
			} );
		} );

		// Keeps Tab/Shift+Tab inside the dialog while it's open.
		wrap.addEventListener( 'keydown', function ( e ) {
			if ( 'Tab' !== e.key ) {
				return;
			}

			var focusable = Array.prototype.filter.call(
				wrap.querySelectorAll( 'button, input, select, textarea, a[href], [tabindex="0"]' ),
				function ( el ) {
					// `:disabled`, not `.disabled` — the latter misses controls locked by a disabled fieldset.
					return ! el.matches( ':disabled' ) && null !== el.offsetParent;
				}
			);

			if ( ! focusable.length ) {
				return;
			}

			var first = focusable[ 0 ];
			var last = focusable[ focusable.length - 1 ];

			if ( e.shiftKey && document.activeElement === first ) {
				e.preventDefault();
				last.focus();
			} else if ( ! e.shiftKey && document.activeElement === last ) {
				e.preventDefault();
				first.focus();
			}
		} );

		document.getElementById( 'iftp-fm-field-conditional-enabled' ).addEventListener( 'change', function ( e ) {
			toggleConditionalBody( e.target.checked );
		} );

		document.getElementById( 'iftp-fm-field-condition-add' ).addEventListener( 'click', function () {
			addConditionRow();
		} );

		var saveButton = document.getElementById( 'iftp-fm-field-modal-save' );
		// The inner label, not .sui-loading-text: that also holds the check icon, which textContent would wipe.
		var saveButtonLabel = document.getElementById( 'iftp-fm-field-modal-save-label' );

		saveButton.addEventListener( 'click', function () {
			if ( ! currentFieldSlug ) {
				return;
			}

			var amountType = document.getElementById( 'iftp-fm-field-amount-type-variable' ).checked ? 'variable' : 'fixed';
			var conditionsEnabled = document.getElementById( 'iftp-fm-field-conditional-enabled' ).checked;
			var conditions = conditionsEnabled ? collectConditions() : [];

			var payload = {
				field_slug: currentFieldSlug,
				amount_type: amountType,
				amount: document.getElementById( 'iftp-fm-field-amount' ).value,
				variable: document.getElementById( 'iftp-fm-field-variable' ).value,
				checkout_label: document.getElementById( 'iftp-fm-field-label' ).value,
				field_label: document.getElementById( 'iftp-fm-field-label-text' ).value,
				description: document.getElementById( 'iftp-fm-field-description' ).value,
				description_placement: document.getElementById( 'iftp-fm-field-description-placement' ).value,
				css_class: document.getElementById( 'iftp-fm-field-css-class' ).value,
				hide_icon: document.getElementById( 'iftp-fm-field-hide-icon' ).checked ? '1' : '',
				hide_methods: document.getElementById( 'iftp-fm-field-hide-methods' ).checked ? '1' : '',
				condition_action: getRadioValue( 'iftp_fm_field_condition_action' ),
				condition_rule: getRadioValue( 'iftp_fm_field_condition_rule' ),
			};

			conditions.forEach( function ( condition, index ) {
				payload[ 'conditions[' + index + '][element_id]' ] = condition.element_id;
				payload[ 'conditions[' + index + '][rule]' ] = condition.rule;
				payload[ 'conditions[' + index + '][value]' ] = condition.value;
			} );

			saveButton.disabled = true;
			saveButtonLabel.textContent = iftpFmFieldSettings.i18n.applying;
			setMessage( '' );

			post( 'iftp_fm_save_field_settings', payload )
				.then( function ( response ) {
					if ( ! response.success ) {
						saveButton.disabled = false;
						saveButtonLabel.textContent = iftpFmFieldSettings.i18n.apply;
						setMessage( ( response.data && response.data.message ) || iftpFmFieldSettings.i18n.error );
						return;
					}

					// I saved straight to the DB behind the builder's React state. Reloading stops Forminator's next
					// Save/Publish from writing its stale copy back over it.
					setMessage( iftpFmFieldSettings.i18n.saved );
					window.location.reload();
				} )
				.catch( function () {
					saveButton.disabled = false;
					saveButtonLabel.textContent = iftpFmFieldSettings.i18n.apply;
					setMessage( iftpFmFieldSettings.i18n.error );
				} );
		} );
	} );

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', iftpFmFieldSettings.nonce );
		body.set( 'form_id', String( iftpFmFieldSettings.formId ) );

		Object.keys( data ).forEach( function ( key ) {
			body.set( key, data[ key ] );
		} );

		return fetch( iftpFmFieldSettings.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } ).then( function ( response ) {
			return response.json();
		} );
	}
} )();
