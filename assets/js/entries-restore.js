/* global iftpFmEntriesStore */
// Puts back the admin's saved view (sessionStorage) when Payment Entries opens bare; per-page is saved server-side.
// Runs in <head>, so the redirect happens before the default view paints.
( function () {
	'use strict';

	if ( 'undefined' === typeof iftpFmEntriesStore || ! iftpFmEntriesStore || ! iftpFmEntriesStore.key ) {
		return;
	}

	var store = iftpFmEntriesStore;
	var VIEW_KEYS = [ 's', 'status', 'form_id', 'date_from', 'date_to', 'pay_method', 'amount_min', 'amount_max', 'orderby', 'order', 'paged' ];
	var VIEW_ITEM = store.key + ':view';
	var RESTORING_ITEM = store.key + ':restoring';
	var INTRO_ITEM = store.key + ':intro_seen';
	var NAV_LOADING_ITEM = 'iftpFmEntriesNavLoading'; // set by entries.js on the click that led here
	var root = document.documentElement;
	var introSeen = null !== read( 'sessionStorage', INTRO_ITEM );
	var continued = null !== read( 'sessionStorage', NAV_LOADING_ITEM );
	var startedAt = Date.now();
	var painted = false;

	// The logo mark spins from first paint until the footer scripts have run; its reveal plays once per tab.
	root.classList.add( 'iftp-fm-page-loading' );
	write( 'sessionStorage', NAV_LOADING_ITEM, null );

	// The first frame runs this just before it paints, so from then on the loading state has been seen.
	window.requestAnimationFrame( function () {
		painted = true;
	} );

	if ( introSeen ) {
		root.classList.add( 'iftp-fm-intro-seen' );
	}

	// Arrived via a click: the previous page was already spinning, so this one carries on rather than restarting.
	if ( continued ) {
		root.classList.add( 'iftp-fm-page-continue' );
	} else {
		root.classList.add( 'iftp-fm-page-skeleton' );
	}

	// Calls done once the empty-state spinner finishes its lap and eases to rest, so the morph back into
	// the logo starts from stillness. Returns the timer (0 if done already ran); entries.js uses it too.
	function afterSpinLap( scope, done ) {
		var orbit = scope.querySelector( '.iftp-fm-empty-state__icon .iftp-fm-mark__orbit' );
		var spin = orbit && 'function' === typeof orbit.getAnimations
			? orbit.getAnimations().filter( function ( animation ) {
				return 'iftp-fm-orbit-spin' === animation.animationName;
			} )[ 0 ]
			: null;
		var timing = spin ? spin.effect.getComputedTiming() : null;

		if ( ! timing ) {
			done();
			return 0;
		}

		var wait = null === timing.progress ? timing.delay - spin.currentTime : ( 1 - timing.progress ) * timing.duration;

		return window.setTimeout( done, Math.max( 0, wait ) );
	}

	window.iftpFmAfterSpinLap = afterSpinLap;

	document.addEventListener( 'DOMContentLoaded', function () {
		var wrap = document.querySelector( '.iftp-fm-entries' );
		// Ready before the spinner ever showed (the 150ms --iftp-fm-mark-delay), so nothing to turn back from.
		var quick = ! continued && Date.now() - startedAt < 150;

		function ready() {
			root.classList.remove( 'iftp-fm-page-loading', 'iftp-fm-page-skeleton' );
			root.classList.add( 'iftp-fm-page-ready' );

			// The skeleton screen made it to the screen: fade the content in over it.
			if ( painted && ! continued ) {
				root.classList.add( 'iftp-fm-page-unveil' );
				// Only this reveal fades in, not rows an AJAX action swaps in later.
				window.setTimeout( function () {
					root.classList.remove( 'iftp-fm-page-unveil' );
				}, 600 );
			}
		}

		if ( wrap && quick ) {
			wrap.setAttribute( 'data-iftp-settle', 'none' );
		}

		// A page that's only redirecting to the saved view never showed its reveal.
		if ( ! introSeen && null === read( 'sessionStorage', RESTORING_ITEM ) && document.querySelector( '.iftp-fm-entries .iftp-fm-empty-state__icon' ) ) {
			write( 'sessionStorage', INTRO_ITEM, '1' );
		}

		if ( quick ) {
			ready();
		} else {
			afterSpinLap( document, ready );
		}
	} );

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

		write( 'sessionStorage', RESTORING_ITEM, null );
		write( 'sessionStorage', VIEW_ITEM, Object.keys( view ).length ? JSON.stringify( view ) : null );
		return;
	}

	// A restore that landed bare again would loop, so I only try once.
	if ( null !== read( 'sessionStorage', RESTORING_ITEM ) ) {
		write( 'sessionStorage', RESTORING_ITEM, null );
		return;
	}

	var saved = savedView();
	var params = new URLSearchParams();

	VIEW_KEYS.forEach( function ( key ) {
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
