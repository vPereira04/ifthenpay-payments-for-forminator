/**
 * Builder-canvas popup for the ifthenpay field's settings. Forminator's own
 * field-settings modal can't render for our field (see
 * `Admin\FieldSettingsModal`'s docblock for why), so this intercepts a click
 * on the field's canvas block — before Forminator's own (broken) click
 * handler opens its modal — and shows our own popup instead, the same
 * capture-phase trick `assets/js/frontend.js` uses to get ahead of
 * Forminator's own submit handler.
 */
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

	/**
	 * Capture-phase listener on `document` — fires before Forminator's own
	 * bubble-phase `onClick` (React's synthetic events are also bubble-phase,
	 * same reasoning as frontend.js's submit interception), so stopping
	 * propagation here keeps Forminator's own (broken, for this field type)
	 * modal from ever opening.
	 */
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

	/**
	 * Neither the left field-picker panel nor the canvas block header render
	 * an icon for our field — both read `<type>.icon` off a client-side field
	 * registry Forminator's builder bundle builds for itself (confirmed
	 * against build/form-scripts.js: `<i className={e.icon}>` in the
	 * `field-selectors` picker list and again in the canvas block's
	 * `.fui-field--icon`), not off `IfthenpayField::$icon`, so a third-party
	 * type's `<i>` renders with no class at all. Injecting the class here is
	 * the only extension point available, same reasoning as the
	 * settings-modal interception above. A MutationObserver, not a one-time
	 * scan, because the builder re-renders both spots as React state changes
	 * (adding/removing other fields, reload after Apply, ...).
	 */
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

	/**
	 * "Show/Hide", "All/Any" and "Amount type" are plain radio groups styled
	 * as Forminator's `sui-side-tabs` pill toggle rather than a `<select>` or
	 * default radio list — these two helpers read/write them by `name`, and
	 * `syncToggleTabsActive()` keeps each option's `.sui-tab-item` wrapper's
	 * `active` class in step whenever a value is set programmatically (a
	 * plain `.checked = true` doesn't touch that class the way a real click
	 * does).
	 */
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
		resetTabs();
		wrap.classList.add( 'iftp-fm-active' );
		document.getElementById( 'iftp-fm-field-modal-close' ).focus();

		// Dimmed until this field's settings arrive, so the previous field's
		// values never flash up as if they were this one's.
		var body = wrap.querySelector( '.sui-box-body' );
		body.setAttribute( 'aria-busy', 'true' );

		var tag = document.getElementById( 'iftp-fm-field-modal-tag' );

		if ( tag ) {
			tag.textContent = '{' + fieldSlug + '}';
		}

		post( 'iftp_fm_get_field_settings', { field_slug: fieldSlug } )
			.finally( function () {
				body.removeAttribute( 'aria-busy' );
			} )
			.then( function ( response ) {
				if ( ! response.success ) {
					setMessage( ( response.data && response.data.message ) || iftpFmFieldSettings.i18n.unsaved );
					return;
				}

				populateForm( response.data );
			} )
			.catch( function () {
				setMessage( iftpFmFieldSettings.i18n.error );
			} );
	}

	/**
	 * Always reopens on the "Settings" tab, regardless of where the
	 * previous field's popup was left.
	 */
	function resetTabs() {
		selectTab( 'settings' );
	}

	function selectTab( name ) {
		document.querySelectorAll( '.iftp-fm-field-tab' ).forEach( function ( tab ) {
			var isActive = name === tab.getAttribute( 'data-tab' );
			tab.classList.toggle( 'iftp-fm-field-tab--active', isActive );
			tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			// Roving tabindex — only the active tab is a Tab stop.
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

	/**
	 * The rows stay visible but locked until the checkbox is ticked — Apply
	 * ignores them while it's off, so I don't let anyone fill them in then.
	 * The body is a `<fieldset>`, so this also covers rows added later.
	 */
	function toggleConditionalBody( enabled ) {
		document.getElementById( 'iftp-fm-field-conditional-body' ).disabled = ! enabled;
	}

	/**
	 * Appends one condition row — a field/operator/value triple, saved as
	 * `conditions[<row index>][element_id|rule|value]` in the same shape
	 * Forminator's own conditional-logic engine (`front.condition.js`)
	 * evaluates for any native field.
	 *
	 * @param {Object} [condition] Existing row values, when populating from a saved field.
	 */
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

	/**
	 * The conditions list as `{element_id, rule, value}` objects, read
	 * straight off the condition rows currently in the DOM.
	 */
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

	/**
	 * Only one of "Fixed amount" / "Amount field" is ever relevant at a
	 * time — the other stays hidden, same as Forminator's own PayPal/Stripe
	 * field settings toggle between their own mutually exclusive options.
	 */
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

			// WAI-ARIA tabs pattern: arrows/Home/End move between tabs.
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
		// Targets the inner label span, not `.sui-loading-text` itself — that
		// span also holds the check icon, and `.textContent =` below would
		// wipe it out (Text setter on a parent replaces all its children).
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

					// We just wrote straight to the DB, bypassing Forminator's
					// React builder state entirely (see FieldSettingsModal's
					// docblock — there's no extension point to update it in
					// place). That state still holds the pre-Apply values, and
					// clicking Forminator's own Save/Publish next would
					// serialize it back over what we just saved. Reloading
					// forces the builder to re-fetch the form and pick up what
					// we actually persisted, before that can happen.
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
