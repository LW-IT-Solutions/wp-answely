/**
 * Answely settings page (Settings → Answely): tabs, connection check, test
 * question, content sync buttons and copy button.
 *
 * Data from window.answelyAdmin: ajax (admin-ajax.php address), nonce, hosts
 * (hosts the test answer may link to) and texts.
 */
( function ( d, c, w ) {
	'use strict';
	if ( ! c ) {
		return;
	}

	/*
	 * Tabs: every panel is in the page; without JavaScript all are shown.
	 */
	var tabs = [].slice.call( d.querySelectorAll( '.answely-wrap .nav-tab[data-tab]' ) );
	var panels = [].slice.call( d.querySelectorAll( '.answely-panel' ) );

	function show( id, focus ) {
		var ok = false;
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-tab' ) === id;
			ok = ok || on;
			t.classList.toggle( 'nav-tab-active', on );
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
		} );
		if ( ! ok ) {
			return;
		}
		panels.forEach( function ( p ) {
			p.hidden = p.id !== 'answely-' + id;
		} );
		try {
			sessionStorage.setItem( 'answely-tab', id );
		} catch ( e ) {}
		if ( focus && history.replaceState ) {
			history.replaceState( null, '', '#' + id );
		}
	}

	tabs.forEach( function ( t ) {
		t.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			show( t.getAttribute( 'data-tab' ), true );
		} );
	} );

	var start = ( location.hash || '' ).slice( 1 );
	if ( ! start ) {
		try {
			start = sessionStorage.getItem( 'answely-tab' ) || '';
		} catch ( e ) {}
	}
	w.addEventListener( 'hashchange', function () {
		var h = ( location.hash || '' ).slice( 1 );
		if ( h ) {
			show( h, false );
		}
	} );
	show( start || 'connection', false );
	if ( ! d.querySelector( '.nav-tab-active' ) && tabs[ 0 ] ) {
		show( tabs[ 0 ].getAttribute( 'data-tab' ), false );
	}

	/*
	 * Rendering helpers: model text only ever as text, links only to allowed hosts.
	 */
	function box( el, cls, text ) {
		el.hidden = false;
		el.className = 'answely-result ' + ( cls || '' );
		el.textContent = text || '';
	}

	function link( u ) {
		try {
			var x = new URL( u );
			if ( ( x.protocol === 'https:' || x.protocol === 'http:' ) && ! x.username && ! x.password && c.hosts.indexOf( x.hostname.toLowerCase() ) >= 0 ) {
				return x.href;
			}
		} catch ( e ) {}
		return '';
	}

	function post( action, data, out, btn, done ) {
		var f = new FormData();
		f.append( 'action', action );
		f.append( 'nonce', c.nonce );
		Object.keys( data || {} ).forEach( function ( k ) {
			f.append( k, data[ k ] );
		} );
		box( out, '', c.working );
		if ( btn ) {
			btn.disabled = true;
		}
		fetch( c.ajax, { method: 'POST', body: f, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || typeof j !== 'object' || ! j.data ) {
					throw Error( 'x' );
				}
				done( j.data, !! j.success );
			} )
			.catch( function () {
				box( out, 'is-bad', c.failed );
			} )
			.then( function () {
				if ( btn ) {
					btn.disabled = false;
				}
			} );
	}

	function showResult( out, r ) {
		box( out, r.level === 'ok' ? 'is-ok' : ( r.level === 'warn' ? 'is-warn' : 'is-bad' ), String( r.message || '' ) );
		if ( Array.isArray( r.details ) && r.details.length ) {
			var ul = d.createElement( 'ul' );
			r.details.forEach( function ( x ) {
				var li = d.createElement( 'li' );
				li.textContent = String( x );
				ul.appendChild( li );
			} );
			out.appendChild( ul );
		}
	}

	function showAnswer( out, r ) {
		if ( ! r.answer ) {
			showResult( out, r );
			return;
		}
		box( out, r.level === 'ok' ? 'is-ok' : 'is-warn', '' );

		var p = d.createElement( 'p' );
		p.textContent = String( r.answer );
		out.appendChild( p );

		if ( Array.isArray( r.sources ) && r.sources.length ) {
			var h = d.createElement( 'strong' );
			h.textContent = c.sources;
			out.appendChild( h );
			var ul = d.createElement( 'ul' );
			r.sources.forEach( function ( s ) {
				var li = d.createElement( 'li' );
				var u = link( s.url );
				if ( u ) {
					var l = d.createElement( 'a' );
					l.href = u;
					l.target = '_blank';
					l.rel = 'noopener noreferrer';
					l.textContent = String( s.title || u );
					li.appendChild( l );
				} else {
					li.textContent = String( s.title || '' );
				}
				ul.appendChild( li );
			} );
			out.appendChild( ul );
		}

		if ( r.note ) {
			var n = d.createElement( 'p' );
			n.className = 'description';
			n.textContent = String( r.note );
			out.appendChild( n );
		}
	}

	/*
	 * Buttons with data-answely-action: copy, check, index, sync_now, test.
	 */
	d.addEventListener( 'click', function ( e ) {
		var b = e.target.closest ? e.target.closest( '[data-answely-action]' ) : null;
		if ( ! b ) {
			return;
		}
		var a = b.getAttribute( 'data-answely-action' );
		var out = d.getElementById( b.getAttribute( 'aria-controls' ) );

		if ( a === 'copy' ) {
			var t = d.getElementById( b.getAttribute( 'data-copy' ) );
			if ( t && navigator.clipboard ) {
				navigator.clipboard.writeText( t.textContent ).then( function () {
					b.textContent = c.copied;
				} );
			}
			return;
		}

		if ( ! out ) {
			return;
		}
		e.preventDefault();

		if ( a === 'check' || a === 'index' || a === 'sync_now' ) {
			post( 'answely_' + a, {}, out, b, function ( r ) {
				showResult( out, r );
			} );
			return;
		}

		if ( a === 'test' ) {
			var q = d.getElementById( 'answely-test-q' );
			var v = q ? q.value.trim() : '';
			if ( ! v ) {
				if ( q ) {
					q.focus();
				}
				return;
			}
			post( 'answely_test', { q: v }, out, b, function ( r ) {
				showAnswer( out, r );
			} );
		}
	} );

	// Enter in the test question field asks the question.
	var tq = d.getElementById( 'answely-test-q' );
	if ( tq ) {
		tq.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Enter' ) {
				e.preventDefault();
				var b = d.querySelector( '[data-answely-action=test]' );
				if ( b ) {
					b.click();
				}
			}
		} );
	}
} )( document, window.answelyAdmin, window );
