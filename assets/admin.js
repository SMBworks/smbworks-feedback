/* Feedback inbox: list on the left, thread on the right. Talks to the REST API through wp.apiFetch. */
( function () {
	const root = document.getElementById( 'smbfb-inbox' );
	const config = window.smbfbInbox;
	if ( ! root || ! config ) {
		return;
	}

	const { __, _n, sprintf } = wp.i18n;
	const api = wp.apiFetch;
	const base = '/' + config.namespace;
	const MENTION = /(@[\p{L}\p{N}][\p{L}\p{N}._-]*)/u;

	const state = {
		status: 'open',
		page_key: '',
		type: '',
		mentioned: false,
		search: '',
		page: 1,
		items: [],
		pages: 1,
		counts: {},
		filter: { pages: [], types: {} },
		selected: 0,
		detail: null,
		replyTo: 0,
		focusReply: 0,
	};

	/* ---- helpers ---- */

	function el( tag, attrs, ...children ) {
		const node = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( function ( [ key, value ] ) {
			if ( null === value || undefined === value || false === value ) {
				return;
			}
			if ( key.startsWith( 'on' ) ) {
				node.addEventListener( key.slice( 2 ), value );
			} else if ( 'className' === key || 'value' === key || 'hidden' === key || 'disabled' === key || 'checked' === key ) {
				node[ key ] = value;
			} else {
				node.setAttribute( key, true === value ? '' : value );
			}
		} );
		node.append( ...children.flat().filter( ( child ) => null !== child && undefined !== child && false !== child ) );
		return node;
	}

	// Plain text with @mentions picked out. Never innerHTML: comments come from guests.
	function richText( text ) {
		return el(
			'div',
			{ className: 'smbfb-text' },
			String( text ).split( MENTION ).map( ( part, i ) => ( i % 2 ? el( 'mark', { className: 'smbfb-at' }, part ) : part ) )
		);
	}

	const relative = new Intl.RelativeTimeFormat( undefined, { numeric: 'auto' } );
	function timeAgo( iso ) {
		const seconds = ( new Date( iso ).getTime() - Date.now() ) / 1000;
		const units = [ [ 'year', 31536000 ], [ 'month', 2592000 ], [ 'week', 604800 ], [ 'day', 86400 ], [ 'hour', 3600 ], [ 'minute', 60 ] ];
		for ( const [ unit, size ] of units ) {
			if ( Math.abs( seconds ) >= size ) {
				return relative.format( Math.round( seconds / size ), unit );
			}
		}
		return __( 'just now', 'smbworks-feedback' );
	}

	function time( iso ) {
		return el( 'time', { datetime: iso, title: new Date( iso ).toLocaleString() }, timeAgo( iso ) );
	}

	function avatar( person, size ) {
		return el( 'img', { className: 'smbfb-avatar', src: person.avatar, alt: '', width: size, height: size } );
	}

	function pageLabel( item ) {
		return item.page_title || item.page_key;
	}

	function notice( message ) {
		const box = root.querySelector( '.smbfb-notice' );
		box.replaceChildren( message ? el( 'p', {}, message ) : '' );
		box.hidden = ! message;
	}

	function fail( error ) {
		notice( ( error && error.message ) || __( 'Something went wrong. Please try again.', 'smbworks-feedback' ) );
	}

	function query( params ) {
		return Object.entries( params )
			.filter( ( [ , value ] ) => '' !== value && false !== value )
			.map( ( [ key, value ] ) => encodeURIComponent( key ) + '=' + encodeURIComponent( value ) )
			.join( '&' );
	}

	/* ---- hash: #item=12&reply=34 ---- */

	function readHash() {
		const params = new URLSearchParams( window.location.hash.slice( 1 ) );
		return { item: parseInt( params.get( 'item' ), 10 ) || 0, reply: parseInt( params.get( 'reply' ), 10 ) || 0 };
	}

	function writeHash() {
		const url = window.location.pathname + window.location.search + ( state.selected ? '#item=' + state.selected : '' );
		window.history.replaceState( null, '', url );
	}

	/* ---- data ---- */

	function loadList( append ) {
		root.setAttribute( 'aria-busy', 'true' );
		const path = base + '/items?' + query( {
			status: state.status,
			page_key: state.page_key,
			type: state.type,
			mentioned: state.mentioned ? 1 : '',
			search: state.search,
			page: state.page,
		} );
		return api( { path } )
			.then( function ( data ) {
				state.items = append ? state.items.concat( data.items ) : data.items;
				state.pages = data.pages;
				state.counts = data.counts;
				state.filter = data.filter;
				renderToolbar();
				renderList();
			} )
			.catch( fail )
			.finally( () => root.setAttribute( 'aria-busy', 'false' ) );
	}

	function select( id, replyId ) {
		state.selected = id;
		state.replyTo = 0;
		state.focusReply = replyId || 0;
		writeHash();
		renderList();
		if ( ! id ) {
			state.detail = null;
			renderDetail();
			return Promise.resolve();
		}
		return api( { path: base + '/items/' + id } )
			.then( function ( item ) {
				state.detail = item;
				renderDetail();
			} )
			.catch( function ( error ) {
				state.detail = null;
				renderDetail();
				fail( error );
			} );
	}

	function afterChange( item ) {
		state.detail = item;
		renderDetail();
		loadList();
	}

	function setStatus( status ) {
		api( { path: base + '/items/' + state.selected, method: 'POST', data: { status } } ).then( afterChange ).catch( fail );
	}

	function sendReply( form, thenStatus ) {
		const textarea = form.querySelector( 'textarea' );
		const body = textarea.value.trim();
		if ( ! body ) {
			textarea.focus();
			return;
		}
		form.querySelectorAll( 'button' ).forEach( ( button ) => ( button.disabled = true ) );
		api( { path: base + '/items/' + state.selected + '/replies', method: 'POST', data: { body, parent_id: state.replyTo } } )
			.then( function ( item ) {
				state.replyTo = 0;
				if ( thenStatus ) {
					return api( { path: base + '/items/' + item.id, method: 'POST', data: { status: thenStatus } } );
				}
				return item;
			} )
			.then( afterChange )
			.catch( function ( error ) {
				form.querySelectorAll( 'button' ).forEach( ( button ) => ( button.disabled = false ) );
				fail( error );
			} );
	}

	/* ---- render ---- */

	function renderShell() {
		root.replaceChildren(
			el( 'div', { className: 'smbfb-notice notice notice-error', hidden: true } ),
			el( 'div', { className: 'smbfb-toolbar' } ),
			el(
				'div',
				{ className: 'smbfb-panes' },
				el( 'div', { className: 'smbfb-list-pane' }, el( 'ul', { className: 'smbfb-list', 'aria-label': __( 'Feedback items', 'smbworks-feedback' ) } ), el( 'div', { className: 'smbfb-more' } ) ),
				el( 'div', { className: 'smbfb-detail', tabindex: '-1' } )
			)
		);
	}

	function renderToolbar() {
		const bar = root.querySelector( '.smbfb-toolbar' );
		const search = bar.querySelector( 'input[type=search]' );
		const hadFocus = search && document.activeElement === search;

		const tabs = el(
			'div',
			{ className: 'smbfb-tabs', role: 'tablist', 'aria-label': __( 'Status', 'smbworks-feedback' ) },
			Object.entries( config.statuses ).map( ( [ key, label ] ) =>
				el(
					'button',
					{
						type: 'button',
						role: 'tab',
						className: 'smbfb-tab',
						'aria-selected': key === state.status ? 'true' : 'false',
						onclick() {
							state.status = key;
							state.page = 1;
							loadList();
						},
					},
					label,
					el( 'span', { className: 'smbfb-count' }, String( state.counts[ key ] || 0 ) )
				)
			)
		);

		const pageSelect = el(
			'select',
			{
				'aria-label': __( 'Filter by page', 'smbworks-feedback' ),
				onchange( event ) {
					state.page_key = event.target.value;
					state.page = 1;
					loadList();
				},
			},
			el( 'option', { value: '' }, __( 'All pages', 'smbworks-feedback' ) ),
			state.filter.pages.map( ( page ) =>
				el( 'option', { value: page.key, selected: page.key === state.page_key }, sprintf( '%1$s (%2$d)', page.title || page.key, page.count ) )
			)
		);
		pageSelect.value = state.page_key;

		const typeSelect = el(
			'select',
			{
				'aria-label': __( 'Filter by type', 'smbworks-feedback' ),
				onchange( event ) {
					state.type = event.target.value;
					state.page = 1;
					loadList();
				},
			},
			el( 'option', { value: '' }, __( 'All types', 'smbworks-feedback' ) ),
			Object.entries( state.filter.types ).map( ( [ key, label ] ) => el( 'option', { value: key }, label ) )
		);
		typeSelect.value = state.type;

		let timer = 0;
		const searchInput = el( 'input', {
			type: 'search',
			value: state.search,
			placeholder: __( 'Search feedback', 'smbworks-feedback' ),
			'aria-label': __( 'Search feedback', 'smbworks-feedback' ),
			oninput( event ) {
				clearTimeout( timer );
				timer = setTimeout( function () {
					state.search = event.target.value.trim();
					state.page = 1;
					loadList();
				}, 300 );
			},
		} );

		const mine = el(
			'label',
			{ className: 'smbfb-mine' },
			el( 'input', {
				type: 'checkbox',
				checked: state.mentioned,
				onchange( event ) {
					state.mentioned = event.target.checked;
					state.page = 1;
					loadList();
				},
			} ),
			' ',
			__( 'Mentions me', 'smbworks-feedback' )
		);

		bar.replaceChildren( tabs, el( 'div', { className: 'smbfb-filters' }, pageSelect, typeSelect, mine, searchInput ) );
		if ( hadFocus ) {
			searchInput.focus();
			searchInput.setSelectionRange( searchInput.value.length, searchInput.value.length );
		}
	}

	function renderList() {
		const list = root.querySelector( '.smbfb-list' );
		const more = root.querySelector( '.smbfb-more' );
		root.classList.toggle( 'has-selection', !! state.selected );

		if ( ! state.items.length ) {
			list.replaceChildren( el( 'li', { className: 'smbfb-empty' }, __( 'No feedback here.', 'smbworks-feedback' ) ) );
		} else {
			list.replaceChildren(
				...state.items.map( ( item ) =>
					el(
						'li',
						{},
						el(
							'button',
							{
								type: 'button',
								className: 'smbfb-row',
								'aria-current': item.id === state.selected ? 'true' : null,
								onclick: () => select( item.id ),
							},
							el(
								'span',
								{ className: 'smbfb-row__top' },
								el( 'span', { className: 'smbfb-type smbfb-type--' + item.type }, item.type_label ),
								el( 'span', { className: 'smbfb-row__page' }, pageLabel( item ) ),
								time( item.last_activity_at )
							),
							el( 'span', { className: 'smbfb-row__excerpt' }, item.comment ),
							el(
								'span',
								{ className: 'smbfb-row__meta' },
								avatar( item.author, 18 ),
								item.author.name,
								item.reply_count ? el( 'span', { className: 'smbfb-row__replies' }, sprintf( _n( '%d reply', '%d replies', item.reply_count, 'smbworks-feedback' ), item.reply_count ) ) : null
							)
						)
					)
				)
			);
		}

		more.replaceChildren(
			state.page < state.pages
				? el(
					'button',
					{
						type: 'button',
						className: 'button',
						onclick() {
							state.page++;
							loadList( true );
						},
					},
					__( 'Load more', 'smbworks-feedback' )
				)
				: ''
		);
	}

	function statusButtons( item ) {
		const buttons = [];
		const button = ( label, status, primary ) =>
			el( 'button', { type: 'button', className: 'button' + ( primary ? ' button-primary' : '' ), onclick: () => setStatus( status ) }, label );

		if ( 'open' === item.status ) {
			buttons.push( button( __( 'Resolve', 'smbworks-feedback' ), 'resolved', true ) );
		} else {
			buttons.push( button( __( 'Reopen', 'smbworks-feedback' ), 'open', false ) );
		}
		if ( 'archived' === item.status ) {
			buttons.push( button( __( 'Unarchive', 'smbworks-feedback' ), 'resolved', false ) );
		} else {
			buttons.push( button( __( 'Archive', 'smbworks-feedback' ), 'archived', false ) );
		}
		return buttons;
	}

	function contextTable( item ) {
		const labels = {
			viewport: __( 'Viewport', 'smbworks-feedback' ),
			screen: __( 'Screen', 'smbworks-feedback' ),
			pixelratio: __( 'Pixel ratio', 'smbworks-feedback' ),
			scroll: __( 'Scrolled', 'smbworks-feedback' ),
			colorscheme: __( 'Color scheme', 'smbworks-feedback' ),
			language: __( 'Language', 'smbworks-feedback' ),
			useragent: __( 'Browser', 'smbworks-feedback' ),
		};
		const rows = [ [ __( 'URL', 'smbworks-feedback' ), el( 'a', { href: item.page_url, target: '_blank', rel: 'noopener' }, item.page_url ) ] ];
		Object.entries( item.context ).forEach( ( [ key, value ] ) => rows.push( [ labels[ key ] || key, String( value ) ] ) );
		if ( item.edit_url ) {
			rows.push( [ __( 'Content', 'smbworks-feedback' ), el( 'a', { href: item.edit_url }, __( 'Edit this page', 'smbworks-feedback' ) ) ] );
		}
		return el(
			'details',
			{ className: 'smbfb-context' },
			el( 'summary', {}, __( 'Page details', 'smbworks-feedback' ) ),
			el( 'table', {}, el( 'tbody', {}, rows.map( ( [ label, value ] ) => el( 'tr', {}, el( 'th', { scope: 'row' }, label ), el( 'td', {}, value ) ) ) ) )
		);
	}

	function replyNode( reply, children, depth ) {
		return el(
			'li',
			{ className: 'smbfb-reply' + ( reply.id === state.focusReply ? ' is-highlighted' : '' ), id: 'smbfb-reply-' + reply.id },
			el(
				'div',
				{ className: 'smbfb-reply__head' },
				avatar( reply.author, 24 ),
				el( 'strong', {}, reply.author.name ),
				time( reply.created_at ),
				el(
					'button',
					{
						type: 'button',
						className: 'button-link',
						onclick() {
							state.replyTo = reply.id;
							renderDetail();
							root.querySelector( '.smbfb-compose textarea' ).focus();
						},
					},
					__( 'Reply', 'smbworks-feedback' )
				)
			),
			richText( reply.body ),
			children[ reply.id ] ? el( 'ul', { className: 'smbfb-thread' + ( depth >= 3 ? ' is-flat' : '' ) }, children[ reply.id ].map( ( child ) => replyNode( child, children, depth + 1 ) ) ) : null
		);
	}

	function thread( replies ) {
		const children = {};
		replies.forEach( function ( reply ) {
			( children[ reply.parent_id ] = children[ reply.parent_id ] || [] ).push( reply );
		} );
		return children[ 0 ] ? el( 'ul', { className: 'smbfb-thread' }, children[ 0 ].map( ( reply ) => replyNode( reply, children, 1 ) ) ) : null;
	}

	function compose( item ) {
		const parent = state.replyTo ? item.replies.find( ( reply ) => reply.id === state.replyTo ) : null;
		const textarea = el( 'textarea', {
			rows: 4,
			maxlength: 5000,
			'aria-label': __( 'Reply', 'smbworks-feedback' ),
			placeholder: __( 'Write a reply. Type @ to notify a teammate.', 'smbworks-feedback' ),
		} );
		const form = el(
			'form',
			{
				className: 'smbfb-compose',
				onsubmit( event ) {
					event.preventDefault();
					sendReply( form );
				},
			},
			parent
				? el(
					'p',
					{ className: 'smbfb-replying' },
					sprintf( __( 'Replying to %s', 'smbworks-feedback' ), parent.author.name ),
					' ',
					el(
						'button',
						{
							type: 'button',
							className: 'button-link',
							onclick() {
								state.replyTo = 0;
								renderDetail();
							},
						},
						__( 'Cancel', 'smbworks-feedback' )
					)
				)
				: null,
			textarea,
			el(
				'div',
				{ className: 'smbfb-compose__actions' },
				el( 'button', { type: 'submit', className: 'button button-primary' }, __( 'Reply', 'smbworks-feedback' ) ),
				'open' === item.status
					? el( 'button', { type: 'button', className: 'button', onclick: () => sendReply( form, 'resolved' ) }, __( 'Reply & resolve', 'smbworks-feedback' ) )
					: null
			)
		);

		textarea.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && ( event.metaKey || event.ctrlKey ) ) {
				event.preventDefault();
				sendReply( form );
			}
		} );
		window.smbfbMentions.attach(
			textarea,
			( term ) => api( { path: base + '/users?' + query( { search: term } ) } ),
			__( 'No matching reviewers', 'smbworks-feedback' )
		);
		return form;
	}

	function renderDetail() {
		const pane = root.querySelector( '.smbfb-detail' );
		const item = state.detail;
		root.classList.toggle( 'has-selection', !! state.selected );

		if ( ! item ) {
			pane.replaceChildren( el( 'p', { className: 'smbfb-placeholder' }, state.selected ? __( 'Loading…', 'smbworks-feedback' ) : __( 'Select feedback to read the thread.', 'smbworks-feedback' ) ) );
			return;
		}

		const draft = pane.querySelector( '.smbfb-compose textarea' );
		const keep = draft && pane.dataset.item === String( item.id ) ? draft.value : '';
		const author = item.author;

		pane.dataset.item = item.id;
		pane.replaceChildren(
			el(
				'button',
				{ type: 'button', className: 'button-link smbfb-back', onclick: () => select( 0 ) },
				'← ' + __( 'All feedback', 'smbworks-feedback' )
			),
			el(
				'header',
				{ className: 'smbfb-detail__head' },
				el( 'div', { className: 'smbfb-detail__badges' }, el( 'span', { className: 'smbfb-type smbfb-type--' + item.type }, item.type_label ), el( 'span', { className: 'smbfb-status smbfb-status--' + item.status }, config.statuses[ item.status ] ) ),
				el( 'h2', {}, el( 'a', { href: item.page_url, target: '_blank', rel: 'noopener' }, pageLabel( item ) ) ),
				el( 'div', { className: 'smbfb-detail__actions' }, statusButtons( item ) )
			),
			el(
				'article',
				{ className: 'smbfb-original' },
				el(
					'div',
					{ className: 'smbfb-reply__head' },
					avatar( author, 32 ),
					el( 'strong', {}, author.name ),
					author.email ? el( 'a', { href: 'mailto:' + author.email }, author.email ) : null,
					author.guest ? el( 'span', { className: 'smbfb-guest' }, __( 'Guest', 'smbworks-feedback' ) ) : null,
					time( item.created_at )
				),
				richText( item.comment ),
				item.resolved_at && 'open' !== item.status
					? el( 'p', { className: 'smbfb-resolved' }, sprintf( __( 'Closed by %1$s %2$s', 'smbworks-feedback' ), item.resolved_by || __( 'someone', 'smbworks-feedback' ), timeAgo( item.resolved_at ) ) )
					: null,
				contextTable( item )
			),
			el( 'h3', { className: 'smbfb-thread-title' }, sprintf( _n( '%d reply', '%d replies', item.replies.length, 'smbworks-feedback' ), item.replies.length ) ),
			thread( item.replies ) || '',
			compose( item )
		);

		if ( keep ) {
			pane.querySelector( '.smbfb-compose textarea' ).value = keep;
		}
		if ( state.focusReply ) {
			const target = document.getElementById( 'smbfb-reply-' + state.focusReply );
			if ( target ) {
				target.scrollIntoView( { block: 'center' } );
			}
			state.focusReply = 0;
		}
	}

	/* ---- start ---- */

	renderShell();
	renderDetail();
	const start = readHash();
	loadList().then( function () {
		if ( start.item ) {
			select( start.item, start.reply );
		}
	} );
	window.addEventListener( 'hashchange', function () {
		const target = readHash();
		if ( target.item && target.item !== state.selected ) {
			select( target.item, target.reply );
		}
	} );
} )();
