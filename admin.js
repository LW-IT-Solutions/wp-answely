/**
 * Answely settings page (Settings → Answely): tabs, connection check, test
 * question, content sync buttons, copy buttons and the confirmation dialog.
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
	 * With JavaScript they work as an ARIA tab list (arrow keys, Home, End).
	 */
	var list = d.querySelector( '.answely-wrap .answely-tabs' );
	var tabs = [].slice.call( d.querySelectorAll( '.answely-wrap .nav-tab[data-tab]' ) );
	var panels = [].slice.call( d.querySelectorAll( '.answely-panel' ) );

	if ( list ) {
		list.setAttribute( 'role', 'tablist' );
	}
	tabs.forEach( function ( t ) {
		t.setAttribute( 'role', 'tab' );
	} );
	panels.forEach( function ( p ) {
		p.setAttribute( 'role', 'tabpanel' );
		p.setAttribute( 'aria-labelledby', p.id.replace( /^answely-/, 'answely-tab-' ) );
	} );

	function show( id, focus ) {
		var ok = false;
		tabs.forEach( function ( t ) {
			var on = t.getAttribute( 'data-tab' ) === id;
			ok = ok || on;
			t.classList.toggle( 'nav-tab-active', on );
			t.setAttribute( 'aria-selected', on ? 'true' : 'false' );
			t.setAttribute( 'tabindex', on ? '0' : '-1' );
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

	tabs.forEach( function ( t, i ) {
		t.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			show( t.getAttribute( 'data-tab' ), true );
		} );
		t.addEventListener( 'keydown', function ( e ) {
			var n = -1;
			if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
				n = ( i + 1 ) % tabs.length;
			} else if ( e.key === 'ArrowLeft' || e.key === 'ArrowUp' ) {
				n = ( i - 1 + tabs.length ) % tabs.length;
			} else if ( e.key === 'Home' ) {
				n = 0;
			} else if ( e.key === 'End' ) {
				n = tabs.length - 1;
			}
			if ( n >= 0 ) {
				e.preventDefault();
				show( tabs[ n ].getAttribute( 'data-tab' ), true );
				tabs[ n ].focus();
			}
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
	var icons = { 'is-ok': 'yes-alt', 'is-warn': 'warning', 'is-bad': 'dismiss' };

	function box( el, cls, text ) {
		el.hidden = false;
		el.className = 'answely-result ' + ( cls || '' );
		el.textContent = '';
		if ( icons[ cls ] ) {
			var i = d.createElement( 'span' );
			i.className = 'answely-result__icon dashicons dashicons-' + icons[ cls ];
			i.setAttribute( 'aria-hidden', 'true' );
			el.appendChild( i );
		}
		if ( text ) {
			var p = d.createElement( 'p' );
			p.className = 'answely-result__text';
			p.textContent = text;
			el.appendChild( p );
		}
	}

	function busy( el ) {
		el.hidden = false;
		el.className = 'answely-result is-busy';
		el.textContent = '';
		var s = d.createElement( 'span' );
		s.className = 'spinner is-active';
		s.setAttribute( 'aria-hidden', 'true' );
		el.appendChild( s );
		var p = d.createElement( 'p' );
		p.className = 'answely-result__text';
		p.textContent = c.working;
		el.appendChild( p );
		el.setAttribute( 'aria-busy', 'true' );
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
		busy( out );
		if ( btn ) {
			btn.disabled = true;
			btn.classList.add( 'is-busy' );
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
				out.removeAttribute( 'aria-busy' );
				if ( btn ) {
					btn.disabled = false;
					btn.classList.remove( 'is-busy' );
				}
			} );
	}

	function level( r ) {
		return r.level === 'ok' ? 'is-ok' : ( r.level === 'warn' ? 'is-warn' : 'is-bad' );
	}

	function showResult( out, r ) {
		box( out, level( r ), String( r.message || '' ) );
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
		p.className = 'answely-result__text';
		p.textContent = String( r.answer );
		out.appendChild( p );

		if ( Array.isArray( r.sources ) && r.sources.length ) {
			var h = d.createElement( 'strong' );
			h.className = 'answely-result__head';
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
	 * Copy buttons: the text of the target element; without the clipboard
	 * API the text is selected so that Ctrl+C copies it.
	 */
	var live = d.createElement( 'div' );
	live.className = 'screen-reader-text';
	live.setAttribute( 'aria-live', 'polite' );
	d.body.appendChild( live );

	function say( text ) {
		live.textContent = '';
		w.setTimeout( function () {
			live.textContent = text;
		}, 50 );
	}

	function copy( b ) {
		var t = d.getElementById( b.getAttribute( 'data-copy' ) );
		if ( ! t ) {
			return;
		}
		var label = b.querySelector( '.answely-copy__text' ) || b;
		var before = label.getAttribute( 'data-label' ) || label.textContent;
		label.setAttribute( 'data-label', before );
		function selectText() {
			var r = d.createRange();
			r.selectNodeContents( t );
			var sel = w.getSelection();
			sel.removeAllRanges();
			sel.addRange( r );
			say( c.copyFailed );
		}
		if ( ! navigator.clipboard || ! w.isSecureContext ) {
			selectText();
			return;
		}
		navigator.clipboard.writeText( t.textContent ).then(
			function () {
				b.classList.add( 'is-done' );
				label.textContent = c.copied;
				say( c.copied );
				w.setTimeout( function () {
					b.classList.remove( 'is-done' );
					label.textContent = before;
				}, 2000 );
			},
			selectText
		);
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
			copy( b );
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

	// Enter in the test question field asks the question; the counter follows the input.
	var tq = d.getElementById( 'answely-test-q' );
	var count = d.getElementById( 'answely-test-count' );
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
		if ( count ) {
			var max = parseInt( tq.getAttribute( 'maxlength' ), 10 ) || 300;
			var upd = function () {
				var n = tq.value.length;
				count.textContent = c.count.replace( '%1$d', n ).replace( '%2$d', max );
				count.classList.toggle( 'is-near', n > max * 0.9 );
			};
			tq.addEventListener( 'input', upd );
			upd();
		}
	}

	// The consent category only matters with the WP Consent API switched on.
	var wpc = d.getElementById( 'answely-wp-consent' );
	var cat = d.getElementById( 'answely-category' );
	if ( wpc && cat ) {
		var sync = function () {
			cat.disabled = ! wpc.checked;
		};
		wpc.addEventListener( 'change', sync );
		sync();
		// A disabled select is not sent; enable it again just before saving.
		if ( wpc.form ) {
			wpc.form.addEventListener( 'submit', function () {
				cat.disabled = false;
			} );
		}
	}

	/*
	 * Confirmation dialog for forms with data-answely-confirm (no browser
	 * dialogs): focus trap, Esc cancels, Enter on the confirm button submits,
	 * focus goes back to the button that opened it.
	 */
	var modal = null;
	var opener = null;
	var pending = null;

	function closeModal() {
		if ( ! modal ) {
			return;
		}
		modal.hidden = true;
		d.body.classList.remove( 'answely-modal-open' );
		pending = null;
		if ( opener && opener.focus ) {
			opener.focus();
		}
	}

	function buildModal() {
		modal = d.createElement( 'div' );
		modal.className = 'answely-modal';
		modal.hidden = true;
		modal.innerHTML = '<div class="answely-modal__backdrop"></div>'
			+ '<div class="answely-modal__box" role="dialog" aria-modal="true" aria-labelledby="answely-modal-text">'
			+ '<span class="dashicons dashicons-warning answely-modal__icon" aria-hidden="true"></span>'
			+ '<p class="answely-modal__text" id="answely-modal-text"></p>'
			+ '<div class="answely-modal__buttons"><button type="button" class="button answely-modal__cancel"></button>'
			+ '<button type="button" class="button button-primary answely-modal__ok"></button></div></div>';
		d.body.appendChild( modal );
		modal.querySelector( '.answely-modal__cancel' ).textContent = c.cancel;
		modal.querySelector( '.answely-modal__backdrop' ).addEventListener( 'click', closeModal );
		modal.querySelector( '.answely-modal__cancel' ).addEventListener( 'click', closeModal );
		modal.querySelector( '.answely-modal__ok' ).addEventListener( 'click', function () {
			var f = pending;
			modal.hidden = true;
			d.body.classList.remove( 'answely-modal-open' );
			pending = null;
			if ( f ) {
				f.setAttribute( 'data-answely-confirmed', '1' );
				if ( f.requestSubmit ) {
					f.requestSubmit();
				} else {
					f.submit();
				}
			}
		} );
		modal.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) {
				e.preventDefault();
				closeModal();
				return;
			}
			if ( e.key === 'Tab' ) {
				var f = [].slice.call( modal.querySelectorAll( 'button' ) );
				var i = f.indexOf( d.activeElement );
				if ( e.shiftKey && i <= 0 ) {
					e.preventDefault();
					f[ f.length - 1 ].focus();
				} else if ( ! e.shiftKey && i === f.length - 1 ) {
					e.preventDefault();
					f[ 0 ].focus();
				}
			}
		} );
	}

	d.addEventListener( 'submit', function ( e ) {
		var f = e.target;
		if ( ! f || ! f.hasAttribute || ! f.hasAttribute( 'data-answely-confirm' ) ) {
			return;
		}
		if ( f.getAttribute( 'data-answely-confirmed' ) === '1' ) {
			f.removeAttribute( 'data-answely-confirmed' );
			return;
		}
		e.preventDefault();
		if ( ! modal ) {
			buildModal();
		}
		opener = d.activeElement;
		pending = f;
		modal.querySelector( '.answely-modal__text' ).textContent = f.getAttribute( 'data-answely-confirm' );
		modal.querySelector( '.answely-modal__ok' ).textContent = f.getAttribute( 'data-answely-confirm-button' ) || 'OK';
		modal.hidden = false;
		d.body.classList.add( 'answely-modal-open' );
		modal.querySelector( '.answely-modal__ok' ).focus();
	} );
} )( document, window.answelyAdmin, window );
