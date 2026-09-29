/**
 * Swaps ifthenpay method logos to their dark variant (`data-logo-dark`)
 * whenever the background they actually render on is dark — whatever theme,
 * dark-mode plugin or admin colour scheme is painting it. I read the painted
 * background instead of `prefers-color-scheme` or any plugin's class names,
 * since neither is something I can rely on.
 *
 * Shared by the frontend field, the ifthenpay Entries table and the Gateway
 * settings table — anything rendering `img[data-logo-dark]` gets it for free.
 */
( function () {
	'use strict';

	var SELECTOR = 'img[data-logo-dark]';
	var pending = false;
	var ctx = null;

	function apply() {
		pending = false;

		document.querySelectorAll( SELECTOR ).forEach( function ( img ) {
			if ( ! img.hasAttribute( 'data-logo-light' ) ) {
				img.setAttribute( 'data-logo-light', img.getAttribute( 'src' ) );
			}

			var src = img.getAttribute( isDarkBackground( img ) ? 'data-logo-dark' : 'data-logo-light' );

			if ( img.getAttribute( 'src' ) !== src ) {
				img.setAttribute( 'src', src );
			}
		} );
	}

	function schedule() {
		if ( pending ) {
			return;
		}

		pending = true;
		window.requestAnimationFrame( apply );
	}

	/**
	 * Composites every translucent background between the logo and the first
	 * opaque one (or the default white canvas), then checks perceived brightness.
	 */
	function isDarkBackground( el ) {
		var layers = [];

		for ( var node = el; node && 1 === node.nodeType; node = node.parentElement ) {
			var color = toRgba( window.getComputedStyle( node ).backgroundColor );

			if ( color && color[ 3 ] > 0 ) {
				layers.push( color );

				if ( color[ 3 ] >= 1 ) {
					break;
				}
			}
		}

		var rgb = [ 255, 255, 255 ];

		for ( var i = layers.length - 1; i >= 0; i-- ) {
			var layer = layers[ i ];

			rgb = rgb.map( function ( value, k ) {
				return layer[ k ] * layer[ 3 ] + value * ( 1 - layer[ 3 ] );
			} );
		}

		return ( 0.299 * rgb[ 0 ] + 0.587 * rgb[ 1 ] + 0.114 * rgb[ 2 ] ) / 255 < 0.5;
	}

	/**
	 * Computed colours are usually `rgb()`/`rgba()`, but browsers keep
	 * `oklch()`/`color()` as authored — those I let a 1px canvas resolve.
	 */
	function toRgba( value ) {
		if ( ! value ) {
			return null;
		}

		if ( /^rgba?\(/.test( value ) ) {
			var parts = value.match( /[\d.]+/g ) || [];

			return parts.length < 3 ? null : [ +parts[ 0 ], +parts[ 1 ], +parts[ 2 ], parts.length > 3 ? +parts[ 3 ] : 1 ];
		}

		ctx = ctx || document.createElement( 'canvas' ).getContext( '2d', { willReadFrequently: true } );

		if ( ! ctx ) {
			return null;
		}

		ctx.clearRect( 0, 0, 1, 1 );
		ctx.fillStyle = value;
		ctx.fillRect( 0, 0, 1, 1 );

		var data = ctx.getImageData( 0, 0, 1, 1 ).data;

		return [ data[ 0 ], data[ 1 ], data[ 2 ], data[ 3 ] / 255 ];
	}

	function watch() {
		schedule();

		if ( window.MutationObserver ) {
			// Nodes added anywhere: AJAX-rendered rows/forms, or an injected dark-mode <style>/<link>.
			new MutationObserver( schedule ).observe( document.documentElement, { childList: true, subtree: true } );

			// Dark-mode toggles usually flip a class/attribute on <html> or <body>.
			var root = new MutationObserver( schedule );
			root.observe( document.documentElement, { attributes: true } );
			root.observe( document.body, { attributes: true } );
		}

		// A late stylesheet only paints once it loads, and animated toggles only settle on transitionend.
		document.addEventListener( 'load', schedule, true );
		document.addEventListener(
			'transitionend',
			function ( e ) {
				if ( 'background-color' === e.propertyName ) {
					schedule();
				}
			},
			true
		);
		window.addEventListener( 'load', schedule );

		if ( window.matchMedia ) {
			var scheme = window.matchMedia( '(prefers-color-scheme: dark)' );

			if ( scheme.addEventListener ) {
				scheme.addEventListener( 'change', schedule );
			}
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', watch );
	} else {
		watch();
	}
} )();
