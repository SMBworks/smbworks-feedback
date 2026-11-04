/* Feedback widget: opens the dialog, captures the page, posts to the REST API. Config is in window.smbfbWidget. */
( function () {
	const config = window.smbfbWidget;
	const root = document.querySelector( '.smbfb' );
	if ( ! config || ! root ) {
		return;
	}

	const STORAGE_KEY = 'smbfb_identity';
	const launch = root.querySelector( '.smbfb-launch' );
	const overlay = root.querySelector( '.smbfb-overlay' );
	const form = root.querySelector( '.smbfb-dialog' );
	const nameInput = form.elements.name;
	const emailInput = form.elements.email;
	const comment = form.elements.comment;
	const submit = form.querySelector( '.smbfb-submit' );
	const status = form.querySelector( '.smbfb-status' );
	let openedAt = 0;
	let returnFocus = null;
	let closeTimer = 0;

	if ( window.smbfbMentions && config.users ) {
		window.smbfbMentions.attach(
			comment,
			function ( term ) {
				const url = config.users + ( config.users.includes( '?' ) ? '&' : '?' ) + 'search=' + encodeURIComponent( term );
				return fetch( url, { headers: { 'X-WP-Nonce': config.nonce }, credentials: 'same-origin' } ).then( function ( response ) {
					return response.ok ? response.json() : [];
				} );
			},
			config.i18n.noMatches
		);
	}

	// Guests: remember name and email between visits, as the original widget did.
	function loadIdentity() {
		if ( config.signedIn ) {
			return;
		}
		try {
			const saved = JSON.parse( window.localStorage.getItem( STORAGE_KEY ) || '{}' );
			nameInput.value = nameInput.value || saved.name || '';
			emailInput.value = emailInput.value || saved.email || '';
		} catch ( e ) {}
	}

	function saveIdentity() {
		if ( config.signedIn ) {
			return;
		}
		try {
			window.localStorage.setItem( STORAGE_KEY, JSON.stringify( { name: nameInput.value.trim(), email: emailInput.value.trim() } ) );
		} catch ( e ) {}
	}

	function setStatus( text, kind ) {
		status.textContent = text;
		status.className = 'smbfb-status' + ( kind ? ' is-' + kind : '' );
	}

	function focusables() {
		return Array.from( form.querySelectorAll( 'input:not([type=hidden]):not([tabindex="-1"]), select, textarea, button' ) ).filter( function ( el ) {
			return ! el.disabled && null !== el.offsetParent;
		} );
	}

	function open() {
		clearTimeout( closeTimer );
		loadIdentity();
		setStatus( '' );
		comment.value = '';
		submit.disabled = false;
		returnFocus = document.activeElement;
		overlay.hidden = false;
		launch.setAttribute( 'aria-expanded', 'true' );
		openedAt = Date.now();
		const first = config.signedIn || ( nameInput.value && emailInput.value ) ? comment : nameInput;
		setTimeout( function () {
			first.focus();
		}, 50 );
	}

	function close() {
		overlay.hidden = true;
		launch.setAttribute( 'aria-expanded', 'false' );
		if ( returnFocus && returnFocus.focus ) {
			returnFocus.focus();
		}
	}

	function context() {
		return {
			viewport: window.innerWidth + 'x' + window.innerHeight,
			screen: window.screen.width + 'x' + window.screen.height,
			pixelRatio: window.devicePixelRatio || 1,
			scroll: Math.round( window.scrollY ),
			userAgent: navigator.userAgent,
			language: navigator.language || '',
			colorScheme: window.matchMedia( '(prefers-color-scheme: dark)' ).matches ? 'dark' : 'light',
		};
	}

	launch.setAttribute( 'aria-expanded', 'false' );
	launch.addEventListener( 'click', open );
	form.querySelector( '.smbfb-cancel' ).addEventListener( 'click', close );
	overlay.addEventListener( 'click', function ( event ) {
		if ( event.target === overlay ) {
			close();
		}
	} );
	document.addEventListener( 'keydown', function ( event ) {
		if ( overlay.hidden ) {
			return;
		}
		if ( 'Escape' === event.key ) {
			close();
		} else if ( 'Tab' === event.key ) {
			// Keep focus inside the dialog.
			const items = focusables();
			const first = items[ 0 ];
			const last = items[ items.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();

		const name = nameInput.value.trim();
		const email = emailInput.value.trim();
		const text = comment.value.trim();

		if ( ! text ) {
			setStatus( config.i18n.empty, 'error' );
			comment.focus();
			return;
		}
		if ( ! config.signedIn && ( ! name || ! email || ! emailInput.checkValidity() ) ) {
			setStatus( config.i18n.required, 'error' );
			( name ? emailInput : nameInput ).focus();
			return;
		}

		submit.disabled = true;
		setStatus( config.i18n.sending );

		const headers = { 'Content-Type': 'application/json' };
		if ( config.nonce ) {
			headers[ 'X-WP-Nonce' ] = config.nonce;
		}

		fetch( config.endpoint, {
			method: 'POST',
			credentials: 'same-origin',
			headers,
			body: JSON.stringify( {
				name,
				email,
				type: form.elements.type.value,
				comment: text,
				page_title: document.title,
				page_url: window.location.href,
				context: context(),
				website: form.elements.website.value,
				elapsed: Date.now() - openedAt,
			} ),
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return {};
				} ).then( function ( data ) {
					if ( ! response.ok ) {
						throw new Error( data && data.message ? data.message : config.i18n.error );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				saveIdentity();
				const notified = data.mentioned && data.mentioned.length ? config.i18n.notified.replace( '%s', data.mentioned.join( ', ' ) ) : config.i18n.sent;
				setStatus( notified, 'success' );
				closeTimer = setTimeout( close, data.mentioned && data.mentioned.length ? 2000 : 1000 );
			} )
			.catch( function ( error ) {
				setStatus( error.message || config.i18n.error, 'error' );
				submit.disabled = false;
			} );
	} );
} )();
