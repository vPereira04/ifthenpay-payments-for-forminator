/* global iftpFmEntriesStore */
/**
 * Payment Entries — remembers the admin's working view (sessionStorage) and
 * entries-per-page (localStorage), and puts them back when the screen is
 * opened bare from the menu, a bookmark or an external link.
 *
 * Runs in <head>, so a restore redirect happens before the default view paints.
 */
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpFmEntriesStore || ! iftpFmEntriesStore || ! iftpFmEntriesStore.key ) {
		return;
	}

	var store = iftpFmEntriesStore;
	var VIEW_KEYS = [ 's', 'status', 'form_id', 'date_from', 'date_to', 'pay_method', 'amount_min', 'amount_max', 'orderby', 'order', 'paged' ];
	var PER_PAGE_RE = /^\d{1,3}$/;
	var VIEW_ITEM = store.key + ':view';
	var PER_PAGE_ITEM = store.key + ':per_page';
	var RESTORING_ITEM = store.key + ':restoring';
	var defaultPerPage = String( store.defaultPerPage );

	// Storage can be missing or throw (private mode, blocked, quota); I just carry on without it.
	function read( area, item ) {
		try {
			return window[ area ].getItem( item );
		} catch ( e ) {
			return null;
		}
	}

	function write( area, item, value ) {
		try {
			if ( null === value ) {
				window[ area ].removeItem( item );
			} else {
				window[ area ].setItem( item, value );
			}
		} catch ( e ) {
			// Nothing saved; the page works the same without it.
		}
	}

	// Known keys with non-empty string values only. Page 1 is the default, so I drop it.
	function cleanView( raw ) {
		var view = {};

		if ( ! raw || '[object Object]' !== Object.prototype.toString.call( raw ) ) {
			return view;
		}

		VIEW_KEYS.forEach( function ( key ) {
			var value = raw[ key ];

			if ( 'string' === typeof value && '' !== value && ! ( 'paged' === key && '1' === value ) ) {
				view[ key ] = value;
			}
		} );

		return view;
	}

	function cleanPerPage( value ) {
		value = 'number' === typeof value ? String( value ) : value;

		return 'string' === typeof value && PER_PAGE_RE.test( value ) && value !== defaultPerPage ? value : null;
	}

	function savedView() {
		try {
			return cleanView( JSON.parse( read( 'sessionStorage', VIEW_ITEM ) || 'null' ) );
		} catch ( e ) {
			return {};
		}
	}

	function isBare() {
		var bare = true;

		new URLSearchParams( window.location.search ).forEach( function ( value, name ) {
			if ( 'page' !== name ) {
				bare = false;
			}
		} );

		return bare;
	}

	if ( ! isBare() ) {
		var view = cleanView( store.view );
		var perPage = cleanPerPage( store.perPage );

		write( 'sessionStorage', RESTORING_ITEM, null );
		write( 'sessionStorage', VIEW_ITEM, Object.keys( view ).length ? JSON.stringify( view ) : null );
		write( 'localStorage', PER_PAGE_ITEM, perPage );
		return;
	}

	// A restore that landed bare again would loop, so I only try once.
	if ( null !== read( 'sessionStorage', RESTORING_ITEM ) ) {
		write( 'sessionStorage', RESTORING_ITEM, null );
		return;
	}

	var saved = savedView();
	var savedPerPage = cleanPerPage( read( 'localStorage', PER_PAGE_ITEM ) );
	var params = new URLSearchParams();

	VIEW_KEYS.forEach( function ( key ) {
		if ( 'paged' === key && savedPerPage ) {
			params.set( 'per_page', savedPerPage ); // same spot filtered_url() puts it
		}

		if ( Object.prototype.hasOwnProperty.call( saved, key ) ) {
			params.set( key, saved[ key ] );
		}
	} );

	if ( ! params.toString() ) {
		return;
	}

	write( 'sessionStorage', RESTORING_ITEM, '1' );
	window.location.replace(
		window.location.pathname + '?page=' + encodeURIComponent( store.pageSlug ) + '&' + params.toString() + window.location.hash
	);
} )();
