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

	var calm = !! ( w.matchMedia && w.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

	// Shows the answer word by word once (about one second in all); with reduced motion at once.
	function reveal( p, text ) {
		var words = text.split( /(\s+)/ );
		if ( calm || words.length < 4 ) {
			p.textContent = text;
			return;
		}
		p.textContent = '';
		p.classList.add( 'is-typing' );
		var i = 0;
		var per = Math.max( 1, Math.ceil( words.length / 40 ) );
		var tick = function () {
			p.textContent += words.slice( i, i + per ).join( '' );
			i += per;
			if ( i < words.length ) {
				w.setTimeout( tick, 25 );
			} else {
				p.classList.remove( 'is-typing' );
			}
		};
		tick();
	}

	function showAnswer( out, r, secs ) {
		if ( ! r.answer ) {
			showResult( out, r );
			return;
		}
		box( out, r.level === 'ok' ? 'is-ok' : 'is-warn', '' );

		var answer = String( r.answer );
		var nSources = Array.isArray( r.sources ) ? r.sources.length : 0;
		if ( typeof secs === 'number' && c.meta ) {
			var m = d.createElement( 'p' );
			m.className = 'answely-result__meta';
			m.textContent = c.meta.replace( '%1$s', secs.toLocaleString( d.documentElement.lang || undefined, { minimumFractionDigits: secs < 1 ? 2 : 1, maximumFractionDigits: secs < 1 ? 2 : 1 } ) ).replace( '%2$d', nSources ).replace( '%3$d', answer.split( /\s+/ ).filter( Boolean ).length );
			out.appendChild( m );
		}

		var p = d.createElement( 'p' );
		p.className = 'answely-result__text';
		// The full text is read once by screen readers; the visible text is revealed.
		var sr = d.createElement( 'span' );
		sr.className = 'screen-reader-text';
		sr.textContent = answer;
		out.appendChild( sr );
		p.setAttribute( 'aria-hidden', 'true' );
		out.appendChild( p );
		reveal( p, answer );

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
					l.className = 'answely-src';
					var ic = d.createElement( 'span' );
					ic.className = 'dashicons dashicons-media-document';
					ic.setAttribute( 'aria-hidden', 'true' );
					var tt = d.createElement( 'span' );
					tt.className = 'answely-src__title';
					tt.textContent = String( s.title || u );
					var pa = d.createElement( 'span' );
					pa.className = 'answely-src__path';
					pa.textContent = new URL( u ).pathname;
					l.appendChild( ic );
					l.appendChild( tt );
					l.appendChild( pa );
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
			var t0 = w.performance ? w.performance.now() : Date.now();
			post( 'answely_test', { q: v }, out, b, function ( r ) {
				showAnswer( out, r, ( ( w.performance ? w.performance.now() : Date.now() ) - t0 ) / 1000 );
				remember( v );
			} );
		}
	} );

	/*
	 * Questions asked on this page, as chips that put the question back in the field (memory only;
	 * asking again is a separate click because every answer counts towards the quota).
	 */
	var asked = [];
	function remember( q ) {
		var wrap = d.getElementById( 'answely-recent' );
		var field = d.getElementById( 'answely-test-q' );
		if ( ! wrap || ! field ) {
			return;
		}
		asked = [ q ].concat( asked.filter( function ( x ) {
			return x !== q;
		} ) ).slice( 0, 5 );
		[].slice.call( wrap.querySelectorAll( '.answely-chip' ) ).forEach( function ( n ) {
			n.remove();
		} );
		asked.forEach( function ( x ) {
			var chip = d.createElement( 'button' );
			chip.type = 'button';
			chip.className = 'answely-chip';
			chip.textContent = x.length > 48 ? x.slice( 0, 47 ) + '\u2026' : x;
			chip.title = c.reuse;
			chip.setAttribute( 'aria-label', c.reuse + ': ' + x );
			chip.addEventListener( 'click', function () {
				field.value = x;
				field.dispatchEvent( new Event( 'input' ) );
				field.focus();
			} );
			wrap.appendChild( chip );
		} );
		wrap.hidden = false;
	}

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
	 * Setup: links of open steps switch to the right tab and focus the field or button.
	 */
	d.addEventListener( 'click', function ( e ) {
		var a = e.target.closest ? e.target.closest( '.answely-step__go' ) : null;
		if ( ! a ) {
			return;
		}
		var id = ( a.getAttribute( 'href' ) || '' ).slice( 1 );
		var target = d.getElementById( id );
		if ( ! target && d.getElementById( 'answely-' + id ) ) {
			e.preventDefault();
			show( id, true );
			var panel = d.getElementById( 'answely-' + id );
			var tab = d.getElementById( 'answely-tab-' + id );
			if ( tab ) {
				tab.focus();
			}
			panel.scrollIntoView( { behavior: calm ? 'auto' : 'smooth', block: 'start' } );
			return;
		}
		if ( target ) {
			e.preventDefault();
			var home = target.closest( '.answely-panel' );
			if ( home && home.hidden ) {
				show( home.id.replace( /^answely-/, '' ), true );
			}
			target.scrollIntoView( { behavior: calm ? 'auto' : 'smooth', block: 'center' } );
			target.focus( { preventScroll: true } );
			target.classList.add( 'answely-flash' );
			w.setTimeout( function () {
				target.classList.remove( 'answely-flash' );
			}, 1600 );
		}
	} );

	// Setup ring: draws in and counts up once.
	var ring = d.querySelector( '.answely-ring[data-answely-done]' );
	if ( ring && ! calm ) {
		var done = parseInt( ring.getAttribute( 'data-answely-done' ), 10 ) || 0;
		var all = parseInt( ring.getAttribute( 'data-answely-total' ), 10 ) || 1;
		var label = ring.querySelector( '.answely-ring__text' );
		ring.classList.add( 'is-animated' );
		if ( label && done > 0 ) {
			var k = 0;
			var up = function () {
				label.textContent = k + '/' + all;
				if ( k < done ) {
					k++;
					w.setTimeout( up, 650 / done );
				}
			};
			up();
		}
	}

	/*
	 * Display tab: the sketch of a visitor's page follows the form before it is saved.
	 */
	var preview = d.querySelector( '[data-answely-preview]' );
	if ( preview ) {
		var form = preview.closest( 'form' );
		var follow = function () {
			var load = form.querySelector( 'input[name="answely[load]"]:checked' );
			var everywhere = ! load || load.value === 'everywhere';
			var wait = form.querySelector( 'input[name="answely[zustimmung]"]' );
			var api = form.querySelector( '#answely-wp-consent' );
			var cat = form.querySelector( '#answely-category' );
			[].slice.call( preview.querySelectorAll( '[data-answely-page]' ) ).forEach( function ( li ) {
				var on = everywhere || li.getAttribute( 'data-answely-page' ) === 'search';
				var st = li.querySelector( '.answely-pages__state' );
				li.classList.toggle( 'is-on', on );
				li.classList.toggle( 'is-off', ! on );
				if ( st ) {
					st.textContent = st.getAttribute( on ? 'data-on' : 'data-off' );
				}
			} );
			var step = preview.querySelector( '[data-answely-step="consent"]' );
			if ( step && wait ) {
				step.hidden = ! wait.checked;
				var txt = step.querySelector( '[data-answely-cat-template]' );
				if ( txt ) {
					txt.textContent = api && api.checked && cat
						? txt.getAttribute( 'data-answely-cat-template' ).replace( '%s', cat.options[ cat.selectedIndex ].text )
						: txt.getAttribute( 'data-answely-cat-off' );
				}
			}
			preview.classList.remove( 'is-pulse' );
			if ( ! calm ) {
				w.requestAnimationFrame( function () {
					preview.classList.add( 'is-pulse' );
				} );
			}
		};
		form.addEventListener( 'change', follow );
	}

	/*
	 * Shortcode builder: the example shortcode, its copy button and the previews follow the fields.
	 */
	var builder = d.querySelector( '[data-answely-builder]' );
	var code = d.getElementById( 'answely-shortcode' );
	if ( builder && code ) {
		builder.hidden = false;
		var inputs = [].slice.call( builder.querySelectorAll( '[data-answely-attr]' ) );
		var build = function () {
			var parts = [ '[answely' ];
			inputs.forEach( function ( i ) {
				var v = i.value.replace( /["\[\]]/g, '' ).replace( /\s+/g, ' ' ).trim();
				var name = i.getAttribute( 'data-answely-attr' );
				if ( v ) {
					parts.push( name + '="' + v + '"' );
				}
				[].slice.call( builder.querySelectorAll( '[data-answely-show="' + name + '"]' ) ).forEach( function ( n ) {
					n.textContent = v || i.getAttribute( 'value' ) || '';
					n.classList.toggle( 'is-default', ! v );
				} );
			} );
			code.textContent = parts.join( ' ' ) + ']';
		};
		inputs.forEach( function ( i ) {
			i.addEventListener( 'input', build );
		} );
		build();
	}

	/*
	 * Content sync: test the excluded paths against recently changed published entries while typing.
	 */
	var pt = d.querySelector( '[data-answely-paths]' );
	var area = d.getElementById( 'answely-exclude' );
	if ( pt && area ) {
		var entries = [];
		try {
			entries = JSON.parse( pt.getAttribute( 'data-answely-paths' ) ) || [];
		} catch ( x ) {}
		var sum = pt.querySelector( '.answely-pathtest__sum' );
		var ul = pt.querySelector( '.answely-pathtest__list' );
		var prefixes = function () {
			return area.value.split( /\r?\n/ ).map( function ( line ) {
				line = line.trim();
				if ( ! line ) {
					return '';
				}
				if ( /^[a-z][a-z0-9+.-]*:\/\//i.test( line ) ) {
					try {
						line = new URL( line ).pathname;
					} catch ( x ) {
						return '';
					}
				}
				line = '/' + line.split( /[?#]/ )[ 0 ].replace( /^\/+/, '' );
				return line === '/' ? '' : line;
			} ).filter( Boolean );
		};
		var test = function () {
			var list = prefixes();
			ul.textContent = '';
			pt.hidden = ! list.length;
			if ( ! list.length ) {
				return;
			}
			var hits = entries.filter( function ( en ) {
				return list.some( function ( pre ) {
					return en[ 0 ].indexOf( pre ) === 0;
				} );
			} );
			pt.classList.toggle( 'has-hits', hits.length > 0 );
			if ( pt.hasAttribute( 'data-answely-plain' ) ) {
				sum.textContent = pt.getAttribute( 'data-answely-plain' );
				return;
			}
			sum.textContent = hits.length
				? c.pathSum.replace( '%1$d', hits.length ).replace( '%2$d', entries.length )
				: c.pathNone;
			hits.slice( 0, 8 ).forEach( function ( en ) {
				var pre = list.filter( function ( x ) {
					return en[ 0 ].indexOf( x ) === 0;
				} ).sort( function ( a, b ) {
					return b.length - a.length;
				} )[ 0 ];
				var li = d.createElement( 'li' );
				var codeEl = d.createElement( 'code' );
				var mark = d.createElement( 'mark' );
				mark.textContent = pre;
				codeEl.appendChild( mark );
				codeEl.appendChild( d.createTextNode( en[ 0 ].slice( pre.length ) ) );
				var t = d.createElement( 'span' );
				t.textContent = en[ 1 ];
				li.appendChild( codeEl );
				li.appendChild( t );
				ul.appendChild( li );
			} );
			if ( hits.length > 8 ) {
				var more = d.createElement( 'li' );
				more.className = 'answely-pathtest__more';
				more.textContent = c.pathMore.replace( '%d', hits.length - 8 );
				ul.appendChild( more );
			}
		};
		area.addEventListener( 'input', test );
		test();
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
