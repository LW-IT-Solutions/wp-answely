/**
 * Answely answer field (block "answely/ask" and shortcode [answely]).
 *
 * Answers in place only when Answely is ready (configuration loaded, consent
 * given); otherwise the form submits normally and opens the search results
 * page of WordPress.
 */
document.addEventListener( 'submit', function ( e ) {
	var f = e.target;
	if ( ! f || ! f.matches || ! f.matches( 'form[data-answely-ask]' ) ) {
		return;
	}

	var i = f.querySelector( 'input[name=answely_q]' );
	var q = i ? String( i.value || '' ).trim().slice( 0, 300 ) : '';
	var A = window.Answely;
	var box = f.parentNode.querySelector( '.answely-ask__answer' );

	if ( ! q || ! A || typeof A.frage !== 'function' || ! box ) {
		return;
	}

	var ok = true;
	try {
		if ( typeof A.diagnose === 'function' ) {
			ok = !! A.diagnose().ready;
		}
	} catch ( x ) {
		ok = false;
	}

	if ( ok ) {
		e.preventDefault();
		A.frage( q, box );
	}
} );
