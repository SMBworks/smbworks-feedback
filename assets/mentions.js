/*
 * @mention autocomplete for a textarea. Shared by the front-end widget and the inbox.
 *
 * smbfbMentions.attach( textarea, search ) where search( term ) resolves to [ { handle, name, avatar } ].
 */
( function () {
	const TRIGGER = /(?:^|[^\p{L}\p{N}_.@])@([\p{L}\p{N}._-]*)$/u;
	let uid = 0;

	function attach( textarea, search, emptyText ) {
		const list = document.createElement( 'ul' );
		const listId = 'smbfb-mentions-' + ++uid;
		let results = [];
		let active = 0;
		let start = -1;
		let timer = 0;
		let request = 0;

		list.id = listId;
		list.className = 'smbfb-mentions';
		list.setAttribute( 'role', 'listbox' );
		list.hidden = true;
		textarea.insertAdjacentElement( 'afterend', list );
		textarea.setAttribute( 'aria-autocomplete', 'list' );
		textarea.setAttribute( 'aria-controls', listId );
		textarea.setAttribute( 'aria-expanded', 'false' );

		function close() {
			list.hidden = true;
			results = [];
			start = -1;
			textarea.setAttribute( 'aria-expanded', 'false' );
			textarea.removeAttribute( 'aria-activedescendant' );
		}

		function render() {
			list.replaceChildren();
			if ( ! results.length ) {
				if ( ! emptyText ) {
					close();
					return;
				}
				const li = document.createElement( 'li' );
				li.className = 'smbfb-mentions__empty';
				li.textContent = emptyText;
				list.append( li );
			}
			results.forEach( function ( user, index ) {
				const li = document.createElement( 'li' );
				li.id = listId + '-' + index;
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', index === active ? 'true' : 'false' );
				if ( user.avatar ) {
					const img = document.createElement( 'img' );
					img.src = user.avatar;
					img.alt = '';
					img.width = 20;
					img.height = 20;
					li.append( img );
				}
				const name = document.createElement( 'span' );
				name.textContent = user.name;
				const handle = document.createElement( 'span' );
				handle.className = 'smbfb-mentions__handle';
				handle.textContent = '@' + user.handle;
				li.append( name, handle );
				// mousedown, not click, so the textarea keeps focus.
				li.addEventListener( 'mousedown', function ( event ) {
					event.preventDefault();
					choose( index );
				} );
				list.append( li );
			} );
			list.hidden = false;
			textarea.setAttribute( 'aria-expanded', 'true' );
			if ( results.length ) {
				textarea.setAttribute( 'aria-activedescendant', listId + '-' + active );
			}
		}

		function choose( index ) {
			const user = results[ index ];
			if ( ! user || start < 0 ) {
				return;
			}
			const caret = textarea.selectionStart;
			const before = textarea.value.slice( 0, start ) + '@' + user.handle + ' ';
			textarea.value = before + textarea.value.slice( caret );
			textarea.setSelectionRange( before.length, before.length );
			close();
			textarea.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		}

		textarea.addEventListener( 'input', function () {
			const match = TRIGGER.exec( textarea.value.slice( 0, textarea.selectionStart ) );
			clearTimeout( timer );
			if ( ! match ) {
				close();
				return;
			}
			const term = match[ 1 ];
			start = textarea.selectionStart - term.length - 1;
			timer = setTimeout( function () {
				const mine = ++request;
				search( term ).then( function ( users ) {
					// Ignore answers to searches the user has already typed past.
					if ( mine !== request || start < 0 ) {
						return;
					}
					results = users || [];
					active = 0;
					render();
				} ).catch( close );
			}, 150 );
		} );

		textarea.addEventListener( 'keydown', function ( event ) {
			if ( list.hidden ) {
				return;
			}
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				event.stopPropagation();
				close();
				return;
			}
			if ( ! results.length ) {
				return;
			}
			if ( 'ArrowDown' === event.key || 'ArrowUp' === event.key ) {
				event.preventDefault();
				active = ( active + ( 'ArrowDown' === event.key ? 1 : -1 ) + results.length ) % results.length;
				render();
			} else if ( 'Enter' === event.key || 'Tab' === event.key ) {
				event.preventDefault();
				choose( active );
			}
		} );

		textarea.addEventListener( 'blur', close );
	}

	window.smbfbMentions = { attach };
} )();
