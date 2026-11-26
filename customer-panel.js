/**
 * Answely customer panel: "My orders" next to the Answely box for signed-in
 * WooCommerce customers.
 *
 * Data from window.AnswelyCustomerPanel: endpoint (admin-ajax.php address),
 * action, nonce, login and logout addresses and the labels. The orders are
 * loaded from this site only when the customer clicks "My orders" and are
 * removed from the page when the panel closes.
 */
( function ( w, d ) {
	'use strict';

	var c = w.AnswelyCustomerPanel;
	if ( ! c ) {
		return;
	}
	var labels = c.labels || {};

	function text( key ) {
		return labels[ key ] || key;
	}

	// Only addresses on this site are used.
	function same( url ) {
		var u = new URL( url, location.origin );
		if ( u.origin !== location.origin || u.username || u.password ) {
			throw Error( 'origin' );
		}
		return u.href;
	}

	var endpoint, login, logout;
	try {
		endpoint = same( c.endpoint );
		login = same( c.login );
		logout = c.logout ? same( c.logout ) : '';
	} catch ( _ ) {
		return;
	}

	var root = d.createElement( 'section' );
	var open = d.createElement( 'button' );
	var panel = d.createElement( 'div' );
	var body = d.createElement( 'div' );
	var close = d.createElement( 'button' );
	var abort = null;
	var generation = 0;

	root.className = 'answely-customer-panel';
	root.setAttribute( 'aria-label', text( 'ss_orders' ) );
	open.type = 'button';
	open.textContent = text( 'ss_orders' );
	panel.hidden = true;
	body.setAttribute( 'aria-live', 'polite' );
	close.type = 'button';
	close.textContent = text( 'ss_close' );
	panel.append( body, close );
	root.append( open, panel );

	function clear() {
		generation++;
		if ( abort ) {
			abort.abort();
		}
		abort = null;
		body.replaceChildren();
		panel.hidden = true;
	}

	function note( value ) {
		var p = d.createElement( 'p' );
		p.textContent = value;
		body.appendChild( p );
	}

	function loginLink() {
		var a = d.createElement( 'a' );
		a.href = login;
		a.textContent = text( 'ss_login' );
		body.appendChild( a );
	}

	// Checks one order row of the answer; throws on anything unexpected.
	function checkRow( row ) {
		if (
			! row ||
			typeof row.id !== 'string' ||
			! /^[a-zA-Z0-9_-]{1,64}$/.test( row.id ) ||
			typeof row.status !== 'string' ||
			row.status.length > 80 ||
			typeof row.total !== 'string' ||
			! /^\d{1,12}(?:\.\d{1,4})?$/.test( row.total ) ||
			! /^[A-Z]{3}$/.test( row.currency ) ||
			typeof row.created_at !== 'string' ||
			row.created_at.length > 30
		) {
			throw Error( 'schema' );
		}
	}

	async function load() {
		clear();
		panel.hidden = false;
		var mine = generation;
		abort = new AbortController();
		var controller = abort;
		var timer = setTimeout( function () {
			controller.abort();
		}, 8000 );

		try {
			var fd = new URLSearchParams();
			if ( c.action ) {
				fd.set( 'action', c.action );
			}
			if ( c.nonce ) {
				fd.set( 'nonce', c.nonce );
			}
			var res = await fetch( endpoint, {
				method: 'POST',
				body: fd,
				credentials: 'same-origin',
				cache: 'no-store',
				redirect: 'error',
				signal: controller.signal,
			} );
			if ( res.status === 401 ) {
				if ( mine === generation ) {
					loginLink();
				}
				return;
			}
			if ( ! res.ok ) {
				throw Error( 'unavailable' );
			}
			var raw = await res.text();
			if ( raw.length > 20000 ) {
				throw Error( 'schema' );
			}
			var data = JSON.parse( raw );
			if ( ! data || ! Array.isArray( data.orders ) || data.orders.length > 5 ) {
				throw Error( 'schema' );
			}
			if ( mine !== generation ) {
				return;
			}
			if ( data.orders.length === 0 ) {
				note( text( 'ss_empty' ) );
			}
			data.orders.forEach( function ( row ) {
				checkRow( row );
				var article = d.createElement( 'article' );
				[
					[ 'ss_order', row.id ],
					[ 'ss_date', row.created_at ],
					[ 'ss_status', row.status ],
					[ 'ss_total', row.total + ' ' + row.currency ],
				].forEach( function ( pair ) {
					var p = d.createElement( 'p' );
					p.textContent = text( pair[ 0 ] ) + ': ' + pair[ 1 ];
					article.appendChild( p );
				} );
				body.appendChild( article );
			} );
			note( text( 'ss_session_note' ) );
			if ( logout ) {
				var a = d.createElement( 'a' );
				a.href = logout;
				a.textContent = text( 'ss_logout' );
				a.addEventListener( 'click', clear );
				body.appendChild( a );
			}
		} catch ( e ) {
			if ( mine === generation && e.name !== 'AbortError' ) {
				body.replaceChildren();
				note( text( 'ss_error' ) );
				loginLink();
			}
		} finally {
			clearTimeout( timer );
		}
	}

	root.addEventListener( 'keydown', function ( e ) {
		if ( e.key === 'Escape' ) {
			clear();
			open.focus();
		}
	} );
	open.addEventListener( 'click', load );
	close.addEventListener( 'click', function () {
		clear();
		open.focus();
	} );
	d.addEventListener( 'visibilitychange', function () {
		if ( d.hidden ) {
			clear();
		}
	} );
	w.addEventListener( 'pagehide', clear );

	var host = d.querySelector( '.answely-box' ) || d.querySelector( 'main' ) || d.body;
	host.appendChild( root );
} )( window, document );
