/**
 * Results Portal admin forms (plain JavaScript, no build step).
 *
 *  - Any <select data-new-input="id"> shows its text box only when "New…" is chosen.
 *  - Results form: typing a registration number looks the student up and picks the
 *    latest term; the subject dropdowns list only that term's subjects, and choosing
 *    a code selects the matching name and vice versa.
 *
 * Page data arrives as window.urpAdmin (see URP_Admin::enqueue_script()).
 */
( function () {
	'use strict';

	const data = window.urpAdmin || {};
	const NEW = data.newValue || '__new__';

	/* ------------------------------------------------ "New…" option toggles */

	function syncNewInput( select ) {
		const input = document.getElementById( select.dataset.newInput );
		if ( ! input ) {
			return;
		}
		const isNew = NEW === select.value;
		input.hidden = ! isNew;
		input.required = isNew;
		input.disabled = ! isNew; // Not submitted unless "New…" is chosen.
	}

	document.querySelectorAll( 'select[data-new-input]' ).forEach( function ( select ) {
		select.addEventListener( 'change', function () {
			syncNewInput( select );
			const input = document.getElementById( select.dataset.newInput );
			if ( NEW === select.value && input ) {
				input.focus();
			}
		} );
		syncNewInput( select );
	} );

	/* ------------------------------------------------------- Results form */

	const regNo = document.getElementById( 'urp-reg-no' );
	const term = document.getElementById( 'urp-term' );
	const code = document.getElementById( 'urp-subject-code' );
	const name = document.getElementById( 'urp-subject-name' );
	const status = document.getElementById( 'urp-student-status' );

	if ( ! regNo || ! term || ! code || ! name || ! data.ajaxUrl ) {
		return;
	}

	function option( value, label, extra ) {
		const el = document.createElement( 'option' );
		el.value = value;
		el.textContent = label;
		Object.assign( el.dataset, extra || {} );
		return el;
	}

	/** Rebuilds both subject dropdowns for the chosen term, keeping a matching selection. */
	function fillSubjects() {
		const subjects = ( data.subjects || {} )[ term.value ] || [];
		const wantCode = code.dataset.selected || ( NEW !== code.value ? code.value : '' );

		code.replaceChildren( option( '', subjects.length ? '— Code —' : '— No subjects yet —' ) );
		name.replaceChildren( option( '', subjects.length ? '— Subject name —' : '— Choose “New subject…” —' ) );
		subjects.forEach( function ( s ) {
			code.append( option( s.code, s.code, { name: s.name } ) );
			name.append( option( s.name, s.name, { code: s.code } ) );
		} );
		code.append( option( NEW, 'New subject…' ) );
		name.append( option( NEW, 'New subject…' ) );

		const match = subjects.find( function ( s ) {
			return s.code === wantCode;
		} );
		if ( match ) {
			code.value = match.code;
			name.value = match.name;
		} else if ( wantCode || ( term.value && ! subjects.length ) ) {
			// A code that isn't used in this term yet (e.g. after a validation error), or a new term.
			code.value = NEW;
			name.value = NEW;
		}
		code.dataset.selected = '';
		syncNewInput( code );
		syncNewInput( name );
	}

	code.addEventListener( 'change', function () {
		const picked = code.selectedOptions[ 0 ];
		name.value = NEW === code.value ? NEW : ( picked && picked.dataset.name ) || '';
		syncNewInput( name );
	} );

	name.addEventListener( 'change', function () {
		const picked = name.selectedOptions[ 0 ];
		code.value = NEW === name.value ? NEW : ( picked && picked.dataset.code ) || '';
		syncNewInput( code );
	} );

	term.addEventListener( 'change', fillSubjects );

	/** The server sends terms newest first. */
	function latestTerm() {
		return ( data.terms || [] )[ 0 ] || '';
	}

	function setStatus( text, isError ) {
		status.textContent = text;
		status.classList.toggle( 'urp-status-error', !! isError );
	}

	let timer = null;
	let lastLookup = '';

	function lookupStudent() {
		const value = regNo.value.trim().toUpperCase();
		if ( value === lastLookup ) {
			return;
		}
		lastLookup = value;
		if ( ! /^[A-Z0-9][A-Z0-9-]{2,31}$/.test( value ) ) {
			setStatus( value ? 'Keep typing the registration number…' : '', false );
			return;
		}

		setStatus( 'Looking up ' + value + '…', false );
		const body = new URLSearchParams( { action: 'urp_student', _ajax_nonce: data.nonce, reg_no: value } );

		fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( result ) {
				if ( value !== lastLookup ) {
					return; // The user typed something else meanwhile.
				}
				if ( ! result.success ) {
					setStatus( 'Could not look up the student. Reload the page and try again.', true );
					return;
				}
				if ( ! result.data.found ) {
					setStatus( 'No student has this registration number. Add the student first.', true );
					return;
				}

				const s = result.data;
				const existing = Object.keys( s.terms ).map( function ( t ) {
					return t + ' (' + s.terms[ t ] + ')';
				} );
				setStatus(
					'✓ ' + s.full_name + ( s.programme ? ' — ' + s.programme : '' ) +
						'. Existing results: ' + ( existing.length ? existing.join( ', ' ) : 'none' ) + '.',
					false
				);

				if ( ! term.value && latestTerm() ) {
					term.value = latestTerm();
					syncNewInput( term );
					fillSubjects();
				}
			} )
			.catch( function () {
				setStatus( 'Could not look up the student. Check your connection and try again.', true );
			} );
	}

	regNo.addEventListener( 'input', function () {
		clearTimeout( timer );
		timer = setTimeout( lookupStudent, 400 );
	} );
	regNo.addEventListener( 'change', lookupStudent );

	fillSubjects();
	if ( regNo.value ) {
		lookupStudent();
	}
}() );
