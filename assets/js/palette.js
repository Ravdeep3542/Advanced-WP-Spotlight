/**
 * Advanced WP Spotlight — command palette front-end.
 *
 * Dependency-free. Reads its bootstrap data from window.AWPS_DATA
 * (localized server-side, already capability-filtered) and exposes a
 * small extension API on window.AWPS for third-party scripts.
 *
 * Destructive commands (Phase 2) never execute directly: they open a
 * confirmation panel first (with typed confirmation for irreversible
 * actions), render in a separate "Danger Zone" section that is never
 * auto-selected, and only surface on high-confidence matches.
 */
( function () {
	'use strict';

	var DATA = window.AWPS_DATA;
	if ( ! DATA ) {
		return;
	}

	var SS_KEY = 'awps_index_cache';
	var SEARCH_DEBOUNCE = 180;
	var MAX_COMMAND_RESULTS = 12;
	var MAX_RECENT_SHOWN = 5;
	// Destructive commands need a prefix/substring-grade match, not a loose
	// subsequence: our scorer yields >= ~700 for substring hits.
	var DESTRUCTIVE_SCORE_FLOOR = 400;
	var UNDO_TOAST_MS = 10000;
	// Uniform typed confirmation for all irreversible actions.
	var CONFIRM_WORD = 'I AGREE';

	/* -------------------------------------------------------------------
	 * State
	 * ---------------------------------------------------------------- */

	var state = {
		open: false,
		mode: 'search',        // 'search' | 'args' | 'confirm'
		query: '',
		commands: [],
		jsHandlers: {},
		favorites: DATA.favorites || [],
		recents: DATA.recents || {},
		items: [],
		selected: 0,
		actionIndex: 0,
		contentResults: [],
		contentLoading: false,
		argsSpec: null,        // { placeholder, searchType, onPick }
		confirmSpec: null,     // { commandId, args, message, requireText, options, selectedOption }
		recordingShortcut: false,
		recordedCombo: '',
		shortcut: DATA.shortcut || 'ctrl+/',
		theme: DATA.theme || 'dark',
		searchTimer: null,
		searchAbort: null
	};

	/* -------------------------------------------------------------------
	 * Command index bootstrapping + sessionStorage mirror
	 * ---------------------------------------------------------------- */

	function loadCommands() {
		state.commands = ( DATA.commands || [] ).slice();

		try {
			sessionStorage.setItem(
				SS_KEY,
				JSON.stringify( { hash: DATA.indexHash, commands: state.commands } )
			);
		} catch ( e ) { /* storage full/blocked — non-fatal */ }
	}

	/* -------------------------------------------------------------------
	 * Fuzzy matching
	 * ---------------------------------------------------------------- */

	function fuzzyScore( query, text ) {
		if ( ! query ) {
			return 0;
		}
		query = query.toLowerCase();
		text = ( text || '' ).toLowerCase();

		var exact = text.indexOf( query );
		if ( exact === 0 ) {
			return 1000 - text.length;
		}
		if ( exact > 0 ) {
			return 700 - exact - text.length * 0.2;
		}

		var score = 0;
		var ti = 0;
		var consecutive = 0;

		for ( var qi = 0; qi < query.length; qi++ ) {
			var ch = query[ qi ];
			if ( ch === ' ' ) {
				consecutive = 0;
				continue;
			}
			var found = text.indexOf( ch, ti );
			if ( found === -1 ) {
				return null;
			}
			if ( found === ti && qi > 0 ) {
				consecutive += 1;
				score += 8 + consecutive * 2;
			} else {
				consecutive = 0;
				score += 2;
			}
			if ( found === 0 || text[ found - 1 ] === ' ' || text[ found - 1 ] === '-' ) {
				score += 10;
			}
			ti = found + 1;
		}

		return score - text.length * 0.3;
	}

	function scoreCommand( query, cmd ) {
		var best = fuzzyScore( query, cmd.title );
		var fields = ( cmd.keywords || [] ).concat( [ cmd.category || '' ] );

		for ( var i = 0; i < fields.length; i++ ) {
			var s = fuzzyScore( query, fields[ i ] );
			if ( s !== null ) {
				s -= 5;
				if ( best === null || s > best ) {
					best = s;
				}
			}
		}
		return best;
	}

	/* -------------------------------------------------------------------
	 * REST helpers
	 * ---------------------------------------------------------------- */

	function rest( path, options ) {
		options = options || {};
		options.headers = options.headers || {};
		options.headers[ 'X-WP-Nonce' ] = DATA.nonce;
		if ( options.body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
		}
		return fetch( DATA.restUrl + path, options ).then( function ( response ) {
			return response.json().then( function ( json ) {
				if ( ! response.ok ) {
					throw new Error( ( json && json.message ) || DATA.i18n.error );
				}
				return json;
			} );
		} );
	}

	/**
	 * Fetch a REST endpoint that streams a file and trigger a browser
	 * download. The filename comes from the Content-Disposition header,
	 * falling back to fallbackName.
	 */
	function downloadFromRest( path, fallbackName, successMessage ) {
		window.fetch( DATA.restUrl + path, {
			headers: { 'X-WP-Nonce': DATA.nonce },
			credentials: 'same-origin'
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				return response.json().then( function ( body ) {
					throw new Error( ( body && body.message ) || DATA.i18n.error );
				} );
			}
			var name = fallbackName || 'download';
			var disposition = response.headers.get( 'Content-Disposition' ) || '';
			var match = disposition.match( /filename="?([^";]+)"?/ );
			if ( match ) {
				name = match[ 1 ];
			}
			return response.blob().then( function ( blob ) {
				var url = window.URL.createObjectURL( blob );
				var a = document.createElement( 'a' );
				a.href = url;
				a.download = name;
				document.body.appendChild( a );
				a.click();
				a.remove();
				window.setTimeout( function () { window.URL.revokeObjectURL( url ); }, 5000 );
				toast( successMessage, 'success' );
			} );
		} ).catch( function ( error ) {
			toast( error.message || DATA.i18n.error, 'error' );
		} );
	}

	function savePref( doAction, value ) {
		return rest( '/prefs', {
			method: 'POST',
			body: JSON.stringify( { do: doAction, value: value } )
		} );
	}

	/* -------------------------------------------------------------------
	 * DOM scaffolding
	 * ---------------------------------------------------------------- */

	var els = {};

	function buildOverlay() {
		var root = document.createElement( 'div' );
		root.id = 'awps-root';
		root.setAttribute( 'aria-hidden', 'true' );
		root.innerHTML =
			'<div class="awps-backdrop"></div>' +
			'<div class="awps-modal" role="dialog" aria-modal="true" aria-label="Command palette">' +
				'<div class="awps-input-wrap">' +
					'<button type="button" class="awps-back" hidden title="' + escapeAttr( DATA.i18n.back ) + '">' +
						'<span class="dashicons dashicons-arrow-left-alt2"></span></button>' +
					'<span class="dashicons dashicons-search awps-input-icon"></span>' +
					'<input type="text" class="awps-input" autocomplete="off" spellcheck="false" ' +
						'placeholder="' + escapeAttr( DATA.i18n.placeholder ) + '" />' +
					'<span class="awps-spinner" hidden></span>' +
				'</div>' +
				'<div class="awps-recorder" hidden>' +
					'<p class="awps-recorder-msg"></p>' +
					'<p class="awps-recorder-combo"></p>' +
					'<p class="awps-recorder-warn" hidden></p>' +
				'</div>' +
				'<div class="awps-confirm" hidden></div>' +
				'<div class="awps-results" role="listbox"></div>' +
				'<div class="awps-index-note" hidden>' + escapeHtml( DATA.i18n.indexBuilding ) + '</div>' +
				'<div class="awps-footer">' + escapeHtml( DATA.i18n.actionsHint ) + '</div>' +
			'</div>';

		document.body.appendChild( root );

		els.root = root;
		els.backdrop = root.querySelector( '.awps-backdrop' );
		els.inputWrap = root.querySelector( '.awps-input-wrap' );
		els.input = root.querySelector( '.awps-input' );
		els.results = root.querySelector( '.awps-results' );
		els.spinner = root.querySelector( '.awps-spinner' );
		els.recorder = root.querySelector( '.awps-recorder' );
		els.recorderMsg = root.querySelector( '.awps-recorder-msg' );
		els.recorderCombo = root.querySelector( '.awps-recorder-combo' );
		els.recorderWarn = root.querySelector( '.awps-recorder-warn' );
		els.confirm = root.querySelector( '.awps-confirm' );
		els.back = root.querySelector( '.awps-back' );
		els.indexNote = root.querySelector( '.awps-index-note' );

		// Reassure users while the background index is still filling up.
		els.indexNote.hidden = ! DATA.indexPending;

		els.backdrop.addEventListener( 'click', close );
		els.input.addEventListener( 'input', onInput );
		els.back.addEventListener( 'click', function () {
			exitArgsMode();
			els.input.focus();
		} );
	}

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = String( str == null ? '' : str );
		return div.innerHTML;
	}

	function escapeAttr( str ) {
		return escapeHtml( str ).replace( /"/g, '&quot;' );
	}

	function setSpinner( visible ) {
		els.spinner.hidden = ! visible;
	}

	/* -------------------------------------------------------------------
	 * Theme (per-user: dark | light | auto)
	 * ---------------------------------------------------------------- */

	function themeIsLight() {
		if ( state.theme === 'auto' ) {
			return !! ( window.matchMedia && window.matchMedia( '(prefers-color-scheme: light)' ).matches );
		}
		return state.theme === 'light';
	}

	function applyTheme() {
		var light = themeIsLight();
		if ( els.root ) {
			els.root.classList.toggle( 'awps-theme-light', light );
		}
		var wrap = document.getElementById( 'awps-toasts' );
		if ( wrap ) {
			wrap.classList.toggle( 'awps-theme-light', light );
		}
	}

	function setTheme( theme ) {
		state.theme = theme;
		applyTheme();
		close();
		savePref( 'theme', theme ).then( function () {
			toast( DATA.i18n.themeSaved, 'success' );
		} ).catch( function ( error ) {
			toast( error.message || DATA.i18n.error, 'error' );
		} );
	}

	/* -------------------------------------------------------------------
	 * Toasts (with optional action button, e.g. Undo)
	 * ---------------------------------------------------------------- */

	function toast( message, kind, sticky, action ) {
		var wrap = document.getElementById( 'awps-toasts' );
		if ( ! wrap ) {
			wrap = document.createElement( 'div' );
			wrap.id = 'awps-toasts';
			document.body.appendChild( wrap );
		}
		wrap.classList.toggle( 'awps-theme-light', themeIsLight() );
		var node = document.createElement( 'div' );
		node.className = 'awps-toast awps-toast-' + ( kind || 'info' );
		node.innerHTML = '<span class="awps-toast-msg">' + escapeHtml( message ) + '</span>';

		var lifetime = sticky ? 0 : ( action ? UNDO_TOAST_MS : 3500 );

		if ( action ) {
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'awps-toast-action';
			btn.textContent = action.label;
			btn.addEventListener( 'click', function () {
				node.remove();
				action.onClick();
			} );
			node.appendChild( btn );
		}

		wrap.appendChild( node );

		if ( lifetime ) {
			setTimeout( function () {
				node.classList.add( 'awps-toast-out' );
				setTimeout( function () { node.remove(); }, 300 );
			}, lifetime );
		}
		return node;
	}

	/* -------------------------------------------------------------------
	 * Result building
	 * ---------------------------------------------------------------- */

	function recencyWeight( id ) {
		var r = state.recents[ id ];
		if ( ! r ) {
			return 0;
		}
		var ageHours = ( Date.now() / 1000 - r.last ) / 3600;
		return r.count * 10 + Math.max( 0, 100 - ageHours );
	}

	function commandById( id ) {
		for ( var i = 0; i < state.commands.length; i++ ) {
			if ( state.commands[ i ].id === id ) {
				return state.commands[ i ];
			}
		}
		return null;
	}

	// Inline destructive actions are only rendered when the corresponding
	// hidden command survived the server's capability filter.
	function canTrashInline() {
		return !! commandById( 'danger:trash-post' );
	}

	function canDeleteUserInline() {
		return !! commandById( 'danger:delete-user-exec' );
	}

	function canDeleteTermInline() {
		return !! commandById( 'danger:delete-term' );
	}

	function canDeleteMenuInline() {
		return !! commandById( 'danger:delete-menu' );
	}

	function isCurrentUserRow( row ) {
		return row.object_type === 'user' && parseInt( row.object_id, 10 ) === parseInt( DATA.currentUserId, 10 );
	}

	function isContextual( cmd ) {
		if ( ! cmd.context || ! cmd.context.length || ! DATA.screen ) {
			return false;
		}
		for ( var i = 0; i < cmd.context.length; i++ ) {
			if ( DATA.screen === cmd.context[ i ] || DATA.screen.indexOf( cmd.context[ i ] ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	function listable( cmd ) {
		return ! cmd.hidden;
	}

	/**
	 * Empty-query view: favorites, contextual suggestions, latest recents.
	 * Destructive commands only ever appear in the trailing Danger Zone.
	 */
	function buildDefaultItems() {
		var sections = [];
		var used = {};
		var danger = [];

		var favs = [];
		state.favorites.forEach( function ( id ) {
			var cmd = commandById( id );
			if ( cmd && listable( cmd ) ) {
				used[ id ] = true;
				if ( cmd.destructive ) {
					danger.push( { kind: 'command', cmd: cmd } );
				} else {
					favs.push( { kind: 'command', cmd: cmd } );
				}
			}
		} );
		if ( favs.length ) {
			sections.push( { label: DATA.i18n.favorites, items: favs } );
		}

		var contextual = [];
		state.commands.forEach( function ( cmd ) {
			if ( ! listable( cmd ) || ! isContextual( cmd ) || used[ cmd.id ] ) {
				return;
			}
			used[ cmd.id ] = true;
			if ( cmd.destructive ) {
				danger.push( { kind: 'command', cmd: cmd } );
			} else if ( contextual.length < 6 ) {
				contextual.push( { kind: 'command', cmd: cmd } );
			}
		} );
		if ( contextual.length ) {
			sections.push( { label: DATA.i18n.suggested, items: contextual } );
		}

		// Latest 5 recents, ordered by last use.
		var recents = Object.keys( state.recents )
			.sort( function ( a, b ) {
				return ( state.recents[ b ].last || 0 ) - ( state.recents[ a ].last || 0 );
			} )
			.map( function ( id ) { return commandById( id ); } )
			.filter( function ( cmd ) { return cmd && listable( cmd ) && ! cmd.destructive && ! used[ cmd.id ]; } )
			.slice( 0, MAX_RECENT_SHOWN )
			.map( function ( cmd ) { return { kind: 'command', cmd: cmd }; } );
		if ( recents.length ) {
			sections.push( { label: DATA.i18n.recent, items: recents } );
		}

		if ( danger.length ) {
			sections.push( { label: DATA.i18n.dangerZone, items: danger, danger: true } );
		}

		return sections;
	}

	/**
	 * Query view: fuzzy commands, async content, destructive last.
	 */
	function buildQueryItems() {
		var sections = [];
		var matched = [];
		var danger = [];

		state.commands.forEach( function ( cmd ) {
			if ( ! listable( cmd ) ) {
				return;
			}
			var s = scoreCommand( state.query, cmd );
			if ( s === null ) {
				return;
			}
			if ( cmd.destructive ) {
				// Stricter confidence floor: destructive commands never surface
				// on loose fuzzy matches, and get no recency/context boost.
				if ( s >= DESTRUCTIVE_SCORE_FLOOR ) {
					danger.push( { cmd: cmd, score: s } );
				}
				return;
			}
			matched.push( { cmd: cmd, score: s + recencyWeight( cmd.id ) * 0.4 + ( isContextual( cmd ) ? 40 : 0 ) } );
		} );

		matched.sort( function ( a, b ) { return b.score - a.score; } );
		danger.sort( function ( a, b ) { return b.score - a.score; } );

		var commandItems = matched.slice( 0, MAX_COMMAND_RESULTS ).map( function ( m ) {
			return { kind: 'command', cmd: m.cmd };
		} );
		if ( commandItems.length ) {
			sections.push( { label: DATA.i18n.suggested, items: commandItems } );
		}

		if ( state.contentResults.length ) {
			sections.push( {
				label: DATA.i18n.content,
				items: state.contentResults.map( function ( row ) {
					return { kind: 'content', row: row };
				} )
			} );
		}

		if ( danger.length ) {
			sections.push( {
				label: DATA.i18n.dangerZone,
				items: danger.slice( 0, 5 ).map( function ( m ) { return { kind: 'command', cmd: m.cmd }; } ),
				danger: true
			} );
		}

		return sections;
	}

	/** Args mode: only content rows of the requested type. */
	function buildArgsItems() {
		var rows = state.contentResults.filter( function ( row ) {
			// The current user can never be picked as a deletion target.
			return ! ( state.argsSpec && state.argsSpec.searchType === 'user' && isCurrentUserRow( row ) );
		} );

		if ( ! rows.length ) {
			return [];
		}
		return [ {
			label: DATA.i18n.content,
			items: rows.map( function ( row ) {
				return { kind: 'argpick', row: row };
			} )
		} ];
	}

	/* -------------------------------------------------------------------
	 * Inline actions per row
	 * ---------------------------------------------------------------- */

	function rowActions( item ) {
		var actions = [];

		if ( item.kind === 'command' ) {
			var pinned = state.favorites.indexOf( item.cmd.id ) !== -1;
			actions.push( {
				name: 'pin',
				icon: pinned ? 'star-filled' : 'star-empty',
				label: pinned ? DATA.i18n.unpin : DATA.i18n.pin
			} );
			return actions;
		}

		if ( item.kind === 'argpick' ) {
			return actions;
		}

		var row = item.row;
		if ( row.view_url ) {
			actions.push( { name: 'view', icon: 'visibility', label: DATA.i18n.view } );
		}
		if ( row.object_type === 'post' && canTrashInline() ) {
			actions.push( { name: 'trash', icon: 'trash', label: DATA.i18n.trashAction, danger: true } );
		}
		// Your own account never gets a Delete action (server blocks it too).
		if ( row.object_type === 'user' && canDeleteUserInline() && ! isCurrentUserRow( row ) ) {
			actions.push( { name: 'delete-user', icon: 'dismiss', label: DATA.i18n.deleteAction, danger: true } );
		}
		if ( row.object_type === 'term' && canDeleteTermInline() ) {
			actions.push( { name: 'delete-term', icon: 'trash', label: DATA.i18n.deleteAction, danger: true } );
		}
		if ( row.object_type === 'menu' && canDeleteMenuInline() ) {
			actions.push( { name: 'delete-menu', icon: 'trash', label: DATA.i18n.deleteAction, danger: true } );
		}
		return actions;
	}

	/* -------------------------------------------------------------------
	 * Rendering
	 * ---------------------------------------------------------------- */

	function render() {
		var sections;
		if ( state.mode === 'args' ) {
			sections = buildArgsItems();
		} else {
			sections = state.query ? buildQueryItems() : buildDefaultItems();
		}

		state.items = [];
		var html = '';

		sections.forEach( function ( section ) {
			html += '<div class="awps-section-label' + ( section.danger ? ' awps-section-danger' : '' ) + '">' +
				escapeHtml( section.label ) + '</div>';
			section.items.forEach( function ( item ) {
				var index = state.items.length;
				state.items.push( item );
				html += renderRow( item, index );
			} );
		} );

		if ( ! state.items.length ) {
			var emptyText = DATA.i18n.noResults;
			if ( state.contentLoading ) {
				emptyText = DATA.i18n.searching;
			} else if ( state.mode === 'args' && state.argsSpec && state.argsSpec.emptyText && state.query.length >= 2 ) {
				emptyText = state.argsSpec.emptyText;
			}
			html += '<div class="awps-empty">' + escapeHtml( emptyText ) + '</div>';
		}

		els.results.innerHTML = html;
		state.selected = Math.min( state.selected, Math.max( 0, state.items.length - 1 ) );
		state.actionIndex = 0;
		applySelection();
		bindRowEvents();
	}

	function renderRow( item, index ) {
		var icon, title, sub, isDanger = false;

		if ( item.kind === 'command' ) {
			icon = item.cmd.icon || 'marker';
			title = item.cmd.title;
			sub = item.cmd.category || '';
			isDanger = !! item.cmd.destructive;
		} else {
			icon = contentIcon( item.row.object_type );
			title = item.row.title;
			sub = item.row.subtitle || '';
		}

		var actionsHtml = '';
		rowActions( item ).forEach( function ( action ) {
			actionsHtml += '<button type="button" class="awps-action' + ( action.danger ? ' awps-action-danger' : '' ) + '" ' +
				'data-action="' + escapeAttr( action.name ) + '" title="' + escapeAttr( action.label ) + '">' +
				'<span class="dashicons dashicons-' + escapeAttr( action.icon ) + '"></span></button>';
		} );

		return '<div class="awps-item' + ( isDanger ? ' awps-item-destructive' : '' ) + '" data-index="' + index + '" role="option">' +
			'<span class="dashicons dashicons-' + escapeAttr( icon ) + ' awps-item-icon"></span>' +
			'<span class="awps-item-body">' +
				'<span class="awps-item-title">' + escapeHtml( title ) + '</span>' +
				'<span class="awps-item-sub">' + escapeHtml( sub ) + '</span>' +
			'</span>' +
			'<span class="awps-item-actions">' + actionsHtml + '</span>' +
		'</div>';
	}

	function contentIcon( type ) {
		switch ( type ) {
			case 'user': return 'admin-users';
			case 'term': return 'category';
			case 'menu': return 'menu';
			case 'order': return 'cart';
			default: return 'admin-page';
		}
	}

	function applySelection() {
		var rows = els.results.querySelectorAll( '.awps-item' );
		rows.forEach( function ( row ) {
			var isSelected = parseInt( row.getAttribute( 'data-index' ), 10 ) === state.selected;
			row.classList.toggle( 'awps-selected', isSelected );

			row.querySelectorAll( '.awps-action' ).forEach( function ( btn, i ) {
				btn.classList.toggle( 'awps-action-focus', isSelected && state.actionIndex === i + 1 );
			} );

			if ( isSelected ) {
				row.scrollIntoView( { block: 'nearest' } );
			}
		} );
	}

	function bindRowEvents() {
		els.results.querySelectorAll( '.awps-item' ).forEach( function ( row ) {
			var index = parseInt( row.getAttribute( 'data-index' ), 10 );

			row.addEventListener( 'mouseenter', function () {
				state.selected = index;
				state.actionIndex = 0;
				applySelection();
			} );
			row.addEventListener( 'click', function ( event ) {
				if ( event.target.closest( '.awps-action' ) ) {
					return;
				}
				state.selected = index;
				executeSelected( 0 );
			} );
			row.querySelectorAll( '.awps-action' ).forEach( function ( btn, i ) {
				btn.addEventListener( 'click', function ( event ) {
					event.stopPropagation();
					state.selected = index;
					executeSelected( i + 1 );
				} );
			} );
		} );
	}

	/* -------------------------------------------------------------------
	 * Content search (debounced, against the pre-built server index)
	 * ---------------------------------------------------------------- */

	function scheduleContentSearch() {
		clearTimeout( state.searchTimer );

		if ( ! state.query || state.query.length < 2 ) {
			// Nothing to search: abort anything in flight and clear the spinner.
			if ( state.searchAbort ) {
				state.searchAbort.abort();
				state.searchAbort = null;
			}
			state.contentResults = [];
			state.contentLoading = false;
			setSpinner( false );
			return;
		}

		state.contentLoading = true;
		state.searchTimer = setTimeout( runContentSearch, SEARCH_DEBOUNCE );
	}

	function runContentSearch() {
		if ( state.searchAbort ) {
			state.searchAbort.abort();
		}
		state.searchAbort = ( typeof AbortController !== 'undefined' ) ? new AbortController() : null;

		var query = state.query;
		var typeFilter = ( state.mode === 'args' && state.argsSpec ) ? state.argsSpec.searchType : '';
		setSpinner( true );

		rest( '/search?term=' + encodeURIComponent( query ) + ( typeFilter ? '&type=' + encodeURIComponent( typeFilter ) : '' ), {
			signal: state.searchAbort ? state.searchAbort.signal : undefined
		} ).then( function ( json ) {
			setSpinner( false );
			state.contentLoading = false;
			if ( state.query !== query ) {
				return; // stale response — spinner already cleared above
			}
			state.contentResults = json.results || [];
			render();
		} ).catch( function ( error ) {
			if ( error && error.name === 'AbortError' ) {
				return; // a newer request owns the spinner now
			}
			state.contentLoading = false;
			setSpinner( false );
		} );
	}

	/* -------------------------------------------------------------------
	 * Execution
	 * ---------------------------------------------------------------- */

	function executeSelected( actionIndex ) {
		var item = state.items[ state.selected ];
		if ( ! item ) {
			return;
		}

		var actions = rowActions( item );
		var action = actionIndex > 0 ? actions[ actionIndex - 1 ] : null;

		if ( item.kind === 'command' ) {
			if ( action && action.name === 'pin' ) {
				toggleFavorite( item.cmd.id );
				return;
			}
			executeCommand( item.cmd );
			return;
		}

		if ( item.kind === 'argpick' ) {
			if ( state.argsSpec && state.argsSpec.onPick ) {
				state.argsSpec.onPick( item.row );
			}
			return;
		}

		// Content rows.
		var row = item.row;
		if ( action ) {
			switch ( action.name ) {
				case 'view':
					window.open( row.view_url, '_blank' );
					return;
				case 'trash':
					openConfirm( {
						commandId: 'danger:trash-post',
						args: { post_id: parseInt( row.object_id, 10 ) },
						message: DATA.i18n.trashPostMsg.replace( '%s', row.title )
					} );
					return;
				case 'delete-user':
					startDeleteUserConfirm( row );
					return;
				case 'delete-term':
					openConfirm( {
						commandId: 'danger:delete-term',
						args: { term_id: parseInt( row.object_id, 10 ) },
						message: DATA.i18n.deleteTermMsg.replace( '%s', row.title ),
						requireText: CONFIRM_WORD
					} );
					return;
				case 'delete-menu':
					openConfirm( {
						commandId: 'danger:delete-menu',
						args: { menu_id: parseInt( row.object_id, 10 ) },
						message: DATA.i18n.deleteMenuMsg.replace( '%s', row.title ),
						requireText: CONFIRM_WORD
					} );
					return;
			}
		}
		close();
		window.location.href = row.url;
	}

	function executeCommand( cmd ) {
		// Destructive rest-commands always route through the confirm panel.
		if ( cmd.destructive && cmd.type === 'rest' ) {
			openConfirm( {
				commandId: cmd.id,
				args: cmd.args || {},
				message: cmd.confirm || cmd.title,
				requireText: cmd.confirm_input || ''
			} );
			return;
		}

		recordUsage( cmd.id );

		switch ( cmd.type ) {
			case 'url':
				close();
				window.location.href = cmd.url;
				break;

			case 'copy':
				var text = cmd.payload === '{current_url}' ? window.location.href : cmd.payload;
				copyText( text );
				close();
				break;

			case 'rest':
				runRestCommand( cmd.id, cmd.args || {}, false );
				close();
				break;

			case 'js':
				var handler = state.jsHandlers[ cmd.id ] || state.jsHandlers[ cmd.payload ];
				if ( handler ) {
					handler( cmd );
				} else {
					document.dispatchEvent( new CustomEvent( 'awps:command', { detail: cmd } ) );
					close();
				}
				break;
		}
	}

	function runRestCommand( commandId, args, confirmed ) {
		var working = toast( DATA.i18n.working, 'info', true );

		var body = { command: commandId, args: args || {} };
		if ( confirmed ) {
			body.confirm = true;
		}

		rest( '/execute', { method: 'POST', body: JSON.stringify( body ) } ).then( function ( json ) {
			working.remove();

			if ( json.undo && json.undo.command ) {
				toast( json.message || DATA.i18n.error, 'success', false, {
					label: DATA.i18n.undo,
					onClick: function () {
						runRestCommand( json.undo.command, json.undo.args || {}, false );
					}
				} );
			} else {
				toast( json.message || DATA.i18n.error, 'success' );
			}

			if ( json.redirect ) {
				window.location.href = json.redirect;
			}
		} ).catch( function ( error ) {
			working.remove();
			toast( error.message || DATA.i18n.error, 'error' );
		} );
	}

	function copyText( text ) {
		var done = function () { toast( DATA.i18n.copied, 'success' ); };
		var fail = function () { toast( DATA.i18n.copyFailed, 'error' ); };

		if ( navigator.clipboard && window.isSecureContext ) {
			navigator.clipboard.writeText( text ).then( done, fail );
			return;
		}
		var area = document.createElement( 'textarea' );
		area.value = text;
		area.style.position = 'fixed';
		area.style.opacity = '0';
		document.body.appendChild( area );
		area.select();
		try {
			document.execCommand( 'copy' ) ? done() : fail();
		} catch ( e ) {
			fail();
		}
		area.remove();
	}

	function toggleFavorite( commandId ) {
		var at = state.favorites.indexOf( commandId );
		if ( at !== -1 ) {
			state.favorites.splice( at, 1 );
		} else {
			state.favorites.push( commandId );
		}
		render();
		savePref( 'favorite', commandId ).catch( function () {} );
	}

	function recordUsage( commandId ) {
		var entry = state.recents[ commandId ] || { count: 0, last: 0 };
		entry.count += 1;
		entry.last = Math.floor( Date.now() / 1000 );
		state.recents[ commandId ] = entry;
		savePref( 'record', commandId ).catch( function () {} );
	}

	/* -------------------------------------------------------------------
	 * Confirmation panel (destructive commands)
	 * ---------------------------------------------------------------- */

	/**
	 * spec: {
	 *   commandId, args,
	 *   message      — what will happen, stated exactly
	 *   requireText  — text the user must type (irreversible tier), optional
	 *   options      — [{value, label}] radio group (e.g. reassignment), optional
	 * }
	 */
	function openConfirm( spec ) {
		state.mode = 'confirm';
		state.confirmSpec = spec;
		spec.selectedOption = spec.options && spec.options.length ? spec.options[ 0 ].value : null;

		els.inputWrap.hidden = true;
		els.results.hidden = true;
		els.confirm.hidden = false;

		var html = '<p class="awps-confirm-title">' + escapeHtml( DATA.i18n.confirmTitle ) + '</p>' +
			'<p class="awps-confirm-msg">' + escapeHtml( spec.message ) + '</p>';

		if ( spec.options && spec.options.length ) {
			html += '<div class="awps-confirm-options">';
			spec.options.forEach( function ( opt, i ) {
				html += '<label><input type="radio" name="awps-confirm-opt" value="' + escapeAttr( opt.value ) + '"' +
					( i === 0 ? ' checked' : '' ) + ' /> ' + escapeHtml( opt.label ) + '</label>';
			} );
			html += '</div>';
		}

		if ( spec.requireText ) {
			html += '<p class="awps-confirm-type-label">' +
				escapeHtml( DATA.i18n.typeToConfirm.replace( '%s', spec.requireText ) ) + '</p>' +
				'<input type="text" class="awps-confirm-type" autocomplete="off" spellcheck="false" />';
		}

		html += '<div class="awps-confirm-buttons">' +
			'<button type="button" class="awps-btn awps-btn-cancel">' + escapeHtml( DATA.i18n.cancel ) + '</button>' +
			'<button type="button" class="awps-btn awps-btn-danger"' + ( spec.requireText ? ' disabled' : '' ) + '>' +
				escapeHtml( DATA.i18n.confirmButton ) + '</button>' +
		'</div>';

		els.confirm.innerHTML = html;

		var cancelBtn = els.confirm.querySelector( '.awps-btn-cancel' );
		var confirmBtn = els.confirm.querySelector( '.awps-btn-danger' );
		var typeInput = els.confirm.querySelector( '.awps-confirm-type' );

		cancelBtn.addEventListener( 'click', closeConfirm );
		confirmBtn.addEventListener( 'click', function () {
			if ( confirmBtn.disabled ) {
				return;
			}
			var spec = state.confirmSpec;
			var args = Object.assign( {}, spec.args );
			if ( spec.selectedOption !== null ) {
				var checked = els.confirm.querySelector( 'input[name="awps-confirm-opt"]:checked' );
				if ( checked ) {
					args[ spec.optionKey || 'reassign' ] = checked.value;
				}
			}
			closeConfirm();
			close();
			recordUsage( spec.commandId );
			runRestCommand( spec.commandId, args, true );
		} );

		if ( typeInput ) {
			typeInput.addEventListener( 'input', function () {
				confirmBtn.disabled =
					typeInput.value.trim().toLowerCase() !== spec.requireText.trim().toLowerCase();
			} );
			// Enter inside the typed-confirm field must never submit.
			typeInput.addEventListener( 'keydown', function ( event ) {
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					cancelBtn.focus();
				}
			} );
		}

		// Default focus is Cancel: Enter-mashing through the panel cancels.
		requestAnimationFrame( function () { cancelBtn.focus(); } );
	}

	function closeConfirm() {
		state.mode = 'search';
		state.confirmSpec = null;
		els.confirm.hidden = true;
		els.confirm.innerHTML = '';
		els.inputWrap.hidden = false;
		els.results.hidden = false;
		els.input.focus();
	}

	/* -------------------------------------------------------------------
	 * Argument mode (e.g. "Delete a User…" suggests real usernames)
	 * ---------------------------------------------------------------- */

	function enterArgsMode( spec ) {
		state.mode = 'args';
		state.argsSpec = spec;
		state.query = '';
		state.contentResults = [];
		state.selected = 0;
		els.input.value = '';
		els.input.placeholder = spec.placeholder;
		els.back.hidden = false;
		render();
		els.input.focus();
	}

	function exitArgsMode() {
		state.mode = 'search';
		state.argsSpec = null;
		state.query = '';
		state.contentResults = [];
		els.input.value = '';
		els.input.placeholder = DATA.i18n.placeholder;
		els.back.hidden = true;
		render();
	}

	function startDeleteUserFlow() {
		enterArgsMode( {
			placeholder: DATA.i18n.searchUsers,
			searchType: 'user',
			emptyText: DATA.i18n.noUsersFound,
			onPick: function ( row ) {
				startDeleteUserConfirm( row );
			}
		} );
	}

	function startDeleteUserConfirm( row ) {
		openConfirm( {
			commandId: 'danger:delete-user-exec',
			args: { user_id: parseInt( row.object_id, 10 ) },
			message: DATA.i18n.deleteUserMsg.replace( '%s', row.title ),
			requireText: CONFIRM_WORD,
			optionKey: 'reassign',
			options: [
				{ value: 'me', label: DATA.i18n.reassignMe },
				{ value: 'none', label: DATA.i18n.reassignNone }
			]
		} );
	}

	/* -------------------------------------------------------------------
	 * Shortcut handling
	 * ---------------------------------------------------------------- */

	function comboFromEvent( event ) {
		var parts = [];
		if ( event.ctrlKey ) { parts.push( 'ctrl' ); }
		if ( event.altKey ) { parts.push( 'alt' ); }
		if ( event.shiftKey ) { parts.push( 'shift' ); }
		if ( event.metaKey ) { parts.push( 'meta' ); }

		var key = ( event.key || '' ).toLowerCase();
		if ( [ 'control', 'alt', 'shift', 'meta' ].indexOf( key ) !== -1 ) {
			return null;
		}
		if ( key === ' ' ) {
			key = 'space';
		}
		parts.push( key );
		return parts.join( '+' );
	}

	function matchesShortcut( event ) {
		var combo = comboFromEvent( event );
		return combo !== null && combo === state.shortcut;
	}

	function formatCombo( combo ) {
		return combo.split( '+' ).map( function ( part ) {
			if ( part === 'meta' ) { return '⌘'; }
			return part.length === 1 ? part.toUpperCase() : part.charAt( 0 ).toUpperCase() + part.slice( 1 );
		} ).join( ' + ' );
	}

	function startShortcutRecorder() {
		state.recordingShortcut = true;
		state.recordedCombo = '';
		els.inputWrap.hidden = true;
		els.results.hidden = true;
		els.recorder.hidden = false;
		els.recorderMsg.textContent = DATA.i18n.recordShortcut;
		els.recorderCombo.textContent = formatCombo( state.shortcut );
		els.recorderWarn.hidden = true;
	}

	function stopShortcutRecorder() {
		state.recordingShortcut = false;
		els.inputWrap.hidden = false;
		els.results.hidden = false;
		els.recorder.hidden = true;
		els.input.focus();
	}

	function onRecorderKeydown( event ) {
		event.preventDefault();
		event.stopPropagation();

		if ( event.key === 'Escape' ) {
			stopShortcutRecorder();
			return;
		}
		if ( event.key === 'Enter' && state.recordedCombo ) {
			var combo = state.recordedCombo;
			savePref( 'shortcut', combo ).then( function ( json ) {
				state.shortcut = json.shortcut || combo;
				toast( DATA.i18n.shortcutSaved + ' ' + formatCombo( state.shortcut ), 'success' );
				stopShortcutRecorder();
				close();
			} ).catch( function ( error ) {
				els.recorderWarn.hidden = false;
				els.recorderWarn.textContent = error.message || DATA.i18n.error;
			} );
			return;
		}

		var combo = comboFromEvent( event );
		if ( ! combo ) {
			return;
		}
		state.recordedCombo = combo;
		els.recorderCombo.textContent = formatCombo( combo );

		var risky = ( DATA.riskyShortcuts || [] ).indexOf( combo ) !== -1;
		els.recorderWarn.hidden = ! risky;
		if ( risky ) {
			els.recorderWarn.textContent = DATA.i18n.shortcutRisky;
		}
	}

	/* -------------------------------------------------------------------
	 * Open / close / global keys
	 * ---------------------------------------------------------------- */

	function open() {
		if ( state.open ) {
			return;
		}
		state.open = true;
		state.mode = 'search';
		state.query = '';
		state.selected = 0;
		state.actionIndex = 0;
		state.contentResults = [];
		state.contentLoading = false;
		setSpinner( false );
		els.input.value = '';
		els.input.placeholder = DATA.i18n.placeholder;
		els.back.hidden = true;
		els.root.classList.add( 'awps-open' );
		els.root.setAttribute( 'aria-hidden', 'false' );
		render();
		requestAnimationFrame( function () { els.input.focus(); } );
	}

	function close() {
		if ( ! state.open ) {
			return;
		}
		state.open = false;
		if ( state.recordingShortcut ) {
			stopShortcutRecorder();
		}
		if ( state.mode === 'confirm' ) {
			closeConfirm();
		}
		state.mode = 'search';
		state.argsSpec = null;
		if ( state.searchAbort ) {
			state.searchAbort.abort();
			state.searchAbort = null;
		}
		setSpinner( false );
		els.root.classList.remove( 'awps-open' );
		els.root.setAttribute( 'aria-hidden', 'true' );
	}

	function onInput() {
		state.query = els.input.value.trim();
		state.selected = 0;
		state.actionIndex = 0;
		scheduleContentSearch();
		render();
	}

	function actionCountFor( item ) {
		return rowActions( item ).length;
	}

	function onGlobalKeydown( event ) {
		if ( ! state.open && matchesShortcut( event ) ) {
			event.preventDefault();
			open();
			return;
		}

		if ( ! state.open ) {
			return;
		}

		if ( state.recordingShortcut ) {
			onRecorderKeydown( event );
			return;
		}

		// Confirm mode: only Escape is intercepted; Tab/Enter follow native
		// focus so Enter activates whichever button is focused (Cancel by default).
		if ( state.mode === 'confirm' ) {
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				closeConfirm();
			}
			return;
		}

		switch ( event.key ) {
			case 'Escape':
				event.preventDefault();
				if ( state.mode === 'args' ) {
					exitArgsMode();
				} else {
					close();
				}
				break;

			case 'ArrowDown':
				event.preventDefault();
				state.selected = Math.min( state.selected + 1, state.items.length - 1 );
				state.actionIndex = 0;
				applySelection();
				break;

			case 'ArrowUp':
				event.preventDefault();
				state.selected = Math.max( state.selected - 1, 0 );
				state.actionIndex = 0;
				applySelection();
				break;

			case 'Tab':
				event.preventDefault();
				var count = actionCountFor( state.items[ state.selected ] );
				state.actionIndex = count ? ( state.actionIndex + 1 ) % ( count + 1 ) : 0;
				applySelection();
				break;

			case 'Enter':
				event.preventDefault();
				executeSelected( state.actionIndex );
				break;
		}
	}

	/* -------------------------------------------------------------------
	 * Built-in JS command handlers
	 * ---------------------------------------------------------------- */

	function registerBuiltinHandlers() {
		state.jsHandlers[ 'change-shortcut' ] = function () {
			startShortcutRecorder();
		};
		state.jsHandlers[ 'delete-user' ] = function () {
			startDeleteUserFlow();
		};
		// Single toggle: flips based on the theme currently applied (works
		// in auto mode too, by resolving the effective OS scheme first).
		state.jsHandlers[ 'theme-toggle' ] = function () {
			setTheme( themeIsLight() ? 'dark' : 'light' );
		};
		state.jsHandlers[ 'download-log' ] = function () {
			close();
			downloadFromRest( '/log', 'debug.log', DATA.i18n.logDownloaded );
		};
		// Zip + download an installed plugin or theme (admin-only,
		// validated server-side against the installed list).
		state.jsHandlers[ 'download-package' ] = function ( cmd ) {
			close();
			var args = ( cmd && cmd.args ) || {};
			if ( ! args.slug || ! args.kind ) {
				toast( DATA.i18n.error, 'error' );
				return;
			}
			var path = '/download?kind=' + encodeURIComponent( args.kind ) +
				'&slug=' + encodeURIComponent( args.slug );
			downloadFromRest( path, '', DATA.i18n.packageDownloaded );
		};
	}

	/* -------------------------------------------------------------------
	 * Extension API (window.AWPS)
	 * ---------------------------------------------------------------- */

	window.AWPS = {
		registerCommands: function ( commands ) {
			( commands || [] ).forEach( function ( cmd ) {
				if ( cmd && cmd.id && cmd.title ) {
					cmd.type = cmd.type || 'js';
					if ( cmd.type === 'rest' ) {
						return; // server-side only (capability enforcement)
					}
					state.commands.push( cmd );
				}
			} );
		},

		onCommand: function ( idOrPayload, handler ) {
			state.jsHandlers[ idOrPayload ] = handler;
		},

		open: open,
		close: close,
		toast: toast
	};

	/* -------------------------------------------------------------------
	 * Boot
	 * ---------------------------------------------------------------- */

	function showFirstRunHint() {
		if ( ! DATA.showHint ) {
			return;
		}
		var message = DATA.i18n.hint.replace( '%s', formatCombo( state.shortcut ) );
		toast( message, 'info' );
		savePref( 'dismiss_hint' ).catch( function () {} );
	}

	function bindAdminBarLauncher() {
		var node = document.getElementById( 'wp-admin-bar-awps-launcher' );
		if ( node ) {
			node.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				open();
			} );
		}
	}

	function init() {
		loadCommands();
		buildOverlay();
		applyTheme();
		registerBuiltinHandlers();
		document.addEventListener( 'keydown', onGlobalKeydown, true );
		bindAdminBarLauncher();
		showFirstRunHint();

		// In auto mode, follow OS scheme changes live.
		if ( window.matchMedia ) {
			var mq = window.matchMedia( '(prefers-color-scheme: light)' );
			if ( mq.addEventListener ) {
				mq.addEventListener( 'change', applyTheme );
			}
		}

		document.dispatchEvent( new CustomEvent( 'awps:ready', { detail: window.AWPS } ) );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
