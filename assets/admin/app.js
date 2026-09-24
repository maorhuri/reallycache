/**
 * ReallyCache admin app. Zero dependencies, no build step.
 * All data comes from window.ReallyCacheData (see Admin\AdminPage).
 */
( function () {
	'use strict';

	var RC = window.ReallyCacheData || {};
	var root = document.getElementById( 'reallycache-app' );
	if ( ! root ) {
		return;
	}

	/* ---------------------------------------------------------------- utils */

	function t( key ) {
		return ( RC.i18n && RC.i18n[ key ] ) || key;
	}

	function tf( key, value ) {
		return t( key ).replace( '%s', value );
	}

	function esc( str ) {
		return String( str == null ? '' : str )
			.replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' )
			.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function formatNumber( n ) {
		try {
			return new Intl.NumberFormat( RC.locale || undefined, { notation: n >= 10000 ? 'compact' : 'standard' } ).format( n );
		} catch ( e ) {
			return String( n );
		}
	}

	function formatBytes( bytes ) {
		if ( ! bytes ) {
			return '0 <small>KB</small>';
		}
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
		var value = bytes / Math.pow( 1024, i );
		return esc( value >= 100 ? Math.round( value ) : value.toFixed( 1 ) ) + ' <small>' + units[ i ] + '</small>';
	}

	function formatRemaining( untilTs ) {
		var mins = Math.max( 1, Math.round( ( untilTs - now() ) / 60 ) );
		return mins >= 90 ? tf( 'time_h', Math.round( mins / 60 ) ) : tf( 'time_m', mins );
	}

	// Server clock offset keeps the dev-mode countdown honest.
	var clockOffset = 0;
	function now() {
		return Math.round( Date.now() / 1000 ) + clockOffset;
	}

	function api( path, body ) {
		return fetch( RC.restUrl + path, {
			method: body ? 'POST' : 'GET',
			credentials: 'same-origin',
			headers: {
				'X-WP-Nonce': RC.nonce,
				'Content-Type': 'application/json'
			},
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			if ( ! res.ok ) {
				throw new Error( 'HTTP ' + res.status );
			}
			return res.json();
		} );
	}

	/* ---------------------------------------------------------------- icons */

	function icon( name, cls ) {
		var paths = {
			bolt: '<path d="M13 2 4.5 13.5h6L10.5 22 19 10.5h-6L13 2z" fill="currentColor" stroke="none"/>',
			activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
			server: '<rect x="2" y="3" width="20" height="7" rx="2"/><rect x="2" y="14" width="20" height="7" rx="2"/><path d="M6 6.5h.01M6 17.5h.01"/>',
			pages: '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 13h6M9 17h6"/>',
			db: '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.6 3.6 3 8 3s8-1.4 8-3V5"/><path d="M4 12c0 1.6 3.6 3 8 3s8-1.4 8-3"/>',
			trash: '<path d="M3 6h18"/><path d="M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
			moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
			sliders: '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/>',
			check: '<path d="M20 6 9 17l-5-5"/>',
			warn: '<path d="M10.3 3.9 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
			x: '<path d="M18 6 6 18M6 6l12 12"/>',
			chat: '<path d="M21 11.5a8.5 8.5 0 0 1-8.5 8.5c-1.5 0-3-.4-4.2-1.1L3 20l1.1-5.3A8.5 8.5 0 1 1 21 11.5z"/>',
			flame: '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.4-.5-2-1-3 1.1-2.2 2.3-3.3 4-4 0 2 .8 3.4 2 4.5a6.2 6.2 0 0 1 2 4.5 6 6 0 1 1-12 0c0-1 .3-2 .5-2.5.7 1.5 1.3 2.4 2 3z"/>',
			spinner: '<path d="M21 12a9 9 0 1 1-6.2-8.6"/>'
		};
		return '<svg class="' + ( cls || '' ) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths[ name ] + '</svg>';
	}

	/* ---------------------------------------------------------------- state */

	var state = null;   // Last payload from the server.
	var model = null;   // The settings being edited.
	var saving = false;
	var devTimer = null;

	function settingsEqual( a, b ) {
		return JSON.stringify( a ) === JSON.stringify( b );
	}

	function isDirty() {
		return state && model && ! settingsEqual( state.settings, model );
	}

	/* ------------------------------------------------------------- skeleton */

	function renderSkeleton() {
		root.innerHTML =
			'<div class="rc-canvas">' +
				'<div class="rc-topbar"><div class="rc-skel" style="width:40px;height:40px"></div><div class="rc-skel" style="width:180px;height:20px"></div></div>' +
				'<div class="rc-main">' +
					'<div class="rc-tiles">' + '<div class="rc-skel" style="height:88px"></div>'.repeat( 4 ) + '</div>' +
					'<div class="rc-skel" style="height:120px"></div>' +
					'<div class="rc-skel" style="height:320px"></div>' +
				'</div>' +
			'</div>';
	}

	function renderError() {
		root.innerHTML =
			'<div class="rc-canvas"><div class="rc-main">' +
				'<div class="rc-banner rc-banner--warn">' + icon( 'warn' ) +
					'<div class="rc-banner-text"><strong>' + esc( t( 'err_load' ) ) + '</strong></div>' +
				'</div>' +
			'</div></div>';
	}

	/* --------------------------------------------------------------- render */

	function statusPill() {
		if ( state.dev_mode.active ) {
			return '<span class="rc-pill rc-pill--warn"><span class="rc-pill-dot"></span>' + esc( t( 'status_paused' ) ) + '</span>';
		}
		if ( state.settings.cache_enabled && state.dropin ) {
			return '<span class="rc-pill rc-pill--ok"><span class="rc-pill-dot"></span>' + esc( t( 'status_active' ) ) + '</span>';
		}
		return '<span class="rc-pill rc-pill--off"><span class="rc-pill-dot"></span>' + esc( t( 'status_disabled' ) ) + '</span>';
	}

	function tile( iconName, label, value, sub ) {
		return '<div class="rc-tile">' +
			'<div class="rc-tile-label">' + icon( iconName ) + esc( label ) + '</div>' +
			'<div class="rc-tile-value">' + value + '</div>' +
			( sub ? '<div class="rc-tile-sub">' + esc( sub ) + '</div>' : '' ) +
		'</div>';
	}

	function ttlLabel( seconds ) {
		var map = { 0: 'ttl_forever', 3600: 'ttl_1h', 21600: 'ttl_6h', 43200: 'ttl_12h', 86400: 'ttl_24h', 604800: 'ttl_7d' };
		return map[ seconds ] ? t( map[ seconds ] ) : Math.round( seconds / 3600 ) + 'h';
	}

	function tilesHtml() {
		var cacheValue = state.dev_mode.active ? t( 'value_paused' ) : ( state.settings.cache_enabled ? t( 'value_on' ) : t( 'value_off' ) );

		return '<div class="rc-tiles">' +
			tile( 'activity', t( 'tile_status' ), esc( cacheValue ), tf( 'tile_ttl_sub', ttlLabel( state.settings.ttl ) ) ) +
			tile( 'server', t( 'tile_server' ), esc( state.server.name ), state.server.tier ) +
			tile( 'pages', t( 'tile_pages' ), '<span data-el="tile-pages">' + esc( formatNumber( state.stats.pages ) ) + '</span>' ) +
			tile( 'db', t( 'tile_size' ), '<span data-el="tile-size">' + formatBytes( state.stats.size ) + '</span>' ) +
		'</div>';
	}

	function bannerHtml() {
		if ( state.dropin ) {
			return '';
		}
		return '<div class="rc-banner rc-banner--warn">' + icon( 'warn' ) +
			'<div class="rc-banner-text"><strong>' + esc( t( 'dropin_warn_title' ) ) + '</strong><br>' + esc( t( 'dropin_warn_body' ) ) + '</div>' +
		'</div>';
	}

	function devHtml() {
		var actions;
		if ( state.dev_mode.active ) {
			actions = '<button type="button" class="rc-btn rc-btn--primary rc-btn--sm" data-action="dev" data-duration="off">' + esc( t( 'dev_off' ) ) + '</button>';
		} else {
			actions =
				'<button type="button" class="rc-btn rc-btn--ghost rc-btn--sm" data-action="dev" data-duration="1h">' + esc( t( 'dev_1h' ) ) + '</button>' +
				'<button type="button" class="rc-btn rc-btn--ghost rc-btn--sm" data-action="dev" data-duration="24h">' + esc( t( 'dev_24h' ) ) + '</button>';
		}

		return '<div class="rc-card">' +
			'<div class="rc-card-head">' + icon( 'moon' ) +
				'<span class="rc-card-title">' + esc( t( 'dev_title' ) ) + '</span>' +
				'<span class="rc-card-desc" data-el="dev-desc">' + esc( state.dev_mode.active ? tf( 'dev_active_desc', formatRemaining( state.dev_mode.until ) ) : t( 'dev_desc' ) ) + '</span>' +
			'</div>' +
			'<div class="rc-card-body"><div class="rc-row"><div class="rc-dev-actions">' + actions + '</div></div></div>' +
		'</div>';
	}

	function switchRow( key, title, desc ) {
		return '<div class="rc-row">' +
			'<div class="rc-row-text"><div class="rc-row-title">' + esc( title ) + '</div>' +
				( desc ? '<div class="rc-row-desc">' + esc( desc ) + '</div>' : '' ) + '</div>' +
			'<label class="rc-switch"><input type="checkbox" data-field="' + key + '"' + ( model[ key ] ? ' checked' : '' ) + '>' +
				'<span class="rc-switch-track"></span></label>' +
		'</div>';
	}

	function textareaRow( key, title, desc, placeholder ) {
		return '<div class="rc-row rc-row--col">' +
			'<div class="rc-row-text"><div class="rc-row-title">' + esc( title ) + '</div>' +
				'<div class="rc-row-desc">' + esc( desc ) + '</div></div>' +
			'<textarea class="rc-textarea" data-field="' + key + '" placeholder="' + esc( placeholder ) + '" spellcheck="false">' + esc( model[ key ].join( '\n' ) ) + '</textarea>' +
		'</div>';
	}

	function settingsHtml() {
		var ttlOptions = [ 3600, 21600, 43200, 86400, 604800, 0 ].map( function ( s ) {
			return '<option value="' + s + '"' + ( model.ttl === s ? ' selected' : '' ) + '>' + esc( ttlLabel( s ) ) + '</option>';
		} ).join( '' );

		return '<div class="rc-card">' +
			'<div class="rc-card-head">' + icon( 'sliders' ) +
				'<span class="rc-card-title">' + esc( t( 'settings_title' ) ) + '</span>' +
				'<span class="rc-card-desc">' + esc( t( 'settings_desc' ) ) + '</span>' +
			'</div>' +
			'<div class="rc-card-body">' +
				switchRow( 'cache_enabled', t( 'row_cache' ), t( 'row_cache_desc' ) ) +
				'<div class="rc-row">' +
					'<div class="rc-row-text"><div class="rc-row-title">' + esc( t( 'row_ttl' ) ) + '</div>' +
						'<div class="rc-row-desc">' + esc( t( 'row_ttl_desc' ) ) + '</div></div>' +
					'<select class="rc-select" data-field="ttl">' + ttlOptions + '</select>' +
				'</div>' +
				switchRow( 'separate_mobile', t( 'row_mobile' ), t( 'row_mobile_desc' ) ) +
				switchRow( 'smart_cache', t( 'row_smart' ), t( 'row_smart_desc' ) ) +
				( state.woo_active ? switchRow( 'woocommerce_protection', t( 'row_woo' ), t( 'row_woo_desc' ) ) : '' ) +
				textareaRow( 'excluded_uris', t( 'row_uris' ), t( 'row_uris_desc' ), '/members/*' ) +
				textareaRow( 'excluded_user_agents', t( 'row_uas' ), t( 'row_uas_desc' ), 'SemrushBot' ) +
			'</div>' +
		'</div>';
	}

	function promoHtml() {
		var dismissed = false;
		try {
			dismissed = '1' === window.localStorage.getItem( 'reallycache-promo-dismissed' );
		} catch ( e ) {}

		if ( dismissed || ! RC.brand ) {
			return '';
		}

		var buttons = '';
		if ( RC.brand.whatsapp ) {
			buttons += '<a class="rc-btn rc-btn--primary rc-btn--sm" href="' + esc( RC.brand.whatsapp ) + '" target="_blank" rel="noopener">' + icon( 'chat' ) + esc( t( 'promo_whatsapp' ) ) + '</a>';
		}
		if ( RC.brand.hosting ) {
			buttons += '<a class="rc-btn rc-btn--ghost rc-btn--sm" href="' + esc( RC.brand.hosting ) + '" target="_blank" rel="noopener">' + esc( t( 'promo_hosting' ) ) + '</a>';
		}
		if ( ! buttons ) {
			return '';
		}

		return '<div class="rc-promo" data-el="promo">' +
			'<div class="rc-promo-icon">' + icon( 'bolt' ) + '</div>' +
			'<div class="rc-promo-text">' +
				'<div class="rc-promo-title">' + esc( t( 'promo_title' ) ) + '</div>' +
				'<div class="rc-promo-desc">' + esc( t( 'promo_desc' ) ) + '</div>' +
			'</div>' +
			'<div class="rc-promo-actions">' + buttons + '</div>' +
			'<button type="button" class="rc-promo-close" data-action="promo-dismiss" aria-label="' + esc( t( 'promo_dismiss' ) ) + '">' + icon( 'x' ) + '</button>' +
		'</div>';
	}

	function render() {
		root.innerHTML =
			'<div class="rc-canvas">' +
				'<div class="rc-topbar">' +
					'<div class="rc-logo">' +
						'<div class="rc-logo-mark">' + icon( 'bolt' ).replace( 'currentColor', '#ffffff' ) + '</div>' +
						'<span class="rc-logo-name">Really<em>Cache</em></span>' +
						'<span class="rc-version">v' + esc( state.version ) + '</span>' +
					'</div>' +
					'<div class="rc-topbar-spacer"></div>' +
					'<span data-el="pill">' + statusPill() + '</span>' +
					'<button type="button" class="rc-btn rc-btn--ghost" data-action="warm"' + ( state.warm && state.warm.running ? ' disabled' : '' ) + '>' + icon( 'flame' ) + '<span data-el="warm-label">' + esc( warmLabel() ) + '</span></button>' +
					'<button type="button" class="rc-btn rc-btn--ghost" data-action="purge">' + icon( 'trash' ) + '<span>' + esc( t( 'btn_clear' ) ) + '</span></button>' +
				'</div>' +
				'<div class="rc-main">' +
					bannerHtml() +
					tilesHtml() +
					devHtml() +
					settingsHtml() +
					promoHtml() +
					'<div class="rc-savebar" data-el="savebar">' +
						'<span class="rc-savebar-text">' + esc( t( 'save_pending' ) ) + '</span>' +
						'<button type="button" class="rc-btn rc-btn--subtle rc-btn--sm" data-action="discard">' + esc( t( 'btn_discard' ) ) + '</button>' +
						'<button type="button" class="rc-btn rc-btn--primary rc-btn--sm" data-action="save">' + icon( 'check' ) + '<span>' + esc( t( 'btn_save' ) ) + '</span></button>' +
					'</div>' +
				'</div>' +
				'<div class="rc-footer">' +
					'<span>ReallyCache v' + esc( state.version ) + '</span><span>·</span>' +
					( RC.brand && RC.brand.hosting
						? '<a href="' + esc( RC.brand.hosting ) + '" target="_blank" rel="noopener">' + esc( t( 'footer_by' ) ) + '</a>'
						: '<span>' + esc( t( 'footer_by' ) ) + '</span>' ) +
				'</div>' +
			'</div>' +
			'<div class="rc-toasts" data-el="toasts" aria-live="polite"></div>';

		updateSavebar();
		scheduleDevTick();
		scheduleWarmPoll();
	}

	function warmLabel() {
		return state.warm && state.warm.running
			? tf( 'warm_running', state.warm.done + '/' + state.warm.total )
			: t( 'btn_warm' );
	}

	/* -------------------------------------------------------- partial updates */

	function el( name ) {
		return root.querySelector( '[data-el="' + name + '"]' );
	}

	function updateSavebar() {
		var bar = el( 'savebar' );
		if ( bar ) {
			bar.classList.toggle( 'is-visible', isDirty() && ! saving );
		}
	}

	function scheduleDevTick() {
		if ( devTimer ) {
			clearInterval( devTimer );
			devTimer = null;
		}
		if ( ! state.dev_mode.active ) {
			return;
		}
		devTimer = setInterval( function () {
			if ( state.dev_mode.until <= now() ) {
				refresh();
				return;
			}
			var desc = el( 'dev-desc' );
			if ( desc ) {
				desc.textContent = tf( 'dev_active_desc', formatRemaining( state.dev_mode.until ) );
			}
		}, 30000 );
	}

	// While a warm-up runs, poll for progress. A full re-render would blow
	// away unsaved edits, so with a dirty form only the labels are patched.
	var warmPoll = null;
	function scheduleWarmPoll() {
		if ( warmPoll ) {
			clearTimeout( warmPoll );
			warmPoll = null;
		}
		if ( state && state.warm && state.warm.running ) {
			warmPoll = setTimeout( pollWarm, 3000 );
		}
	}

	function pollWarm() {
		api( 'state' ).then( function ( payload ) {
			if ( isDirty() || saving ) {
				state.warm = payload.warm;
				state.stats = payload.stats;
				var label = el( 'warm-label' );
				if ( label ) {
					label.textContent = warmLabel();
				}
				var pages = el( 'tile-pages' );
				if ( pages ) {
					pages.textContent = formatNumber( payload.stats.pages );
				}
				var size = el( 'tile-size' );
				if ( size ) {
					size.innerHTML = formatBytes( payload.stats.size );
				}
				scheduleWarmPoll();
			} else {
				applyState( payload );
			}
		} ).catch( scheduleWarmPoll );
	}

	function toast( message, ok ) {
		var host = el( 'toasts' );
		if ( ! host ) {
			return;
		}
		var node = document.createElement( 'div' );
		node.className = 'rc-toast ' + ( ok ? 'rc-toast--ok' : 'rc-toast--err' );
		node.innerHTML = icon( ok ? 'check' : 'warn' ) + '<span></span>';
		node.querySelector( 'span' ).textContent = message;
		host.appendChild( node );
		setTimeout( function () {
			node.remove();
		}, 3200 );
	}

	function busyButton( btn, busy ) {
		if ( ! btn ) {
			return;
		}
		btn.disabled = busy;
		var svg = btn.querySelector( 'svg' );
		if ( busy ) {
			btn.dataset.rcIcon = svg ? svg.outerHTML : '';
			if ( svg ) {
				svg.outerHTML = icon( 'spinner', 'rc-spin' );
			}
		} else if ( btn.dataset.rcIcon ) {
			var current = btn.querySelector( 'svg' );
			if ( current ) {
				current.outerHTML = btn.dataset.rcIcon;
			}
			delete btn.dataset.rcIcon;
		}
	}

	/* -------------------------------------------------------------- actions */

	function applyState( payload ) {
		state = payload;
		clockOffset = payload.time ? payload.time - Math.round( Date.now() / 1000 ) : 0;
		model = JSON.parse( JSON.stringify( payload.settings ) );
		render();
	}

	function refresh() {
		return api( 'state' ).then( applyState ).catch( renderError );
	}

	function doPurge( btn ) {
		busyButton( btn, true );
		api( 'purge', {} ).then( function ( payload ) {
			applyState( payload );
			toast( t( 'toast_cleared' ), true );
		} ).catch( function () {
			busyButton( btn, false );
			toast( t( 'toast_error' ), false );
		} );
	}

	function doWarm( btn ) {
		busyButton( btn, true );
		api( 'warm', {} ).then( function ( payload ) {
			applyState( payload );
			toast( t( 'toast_warm' ), true );
		} ).catch( function () {
			busyButton( btn, false );
			toast( t( 'toast_error' ), false );
		} );
	}

	function doDevMode( btn, duration ) {
		busyButton( btn, true );
		api( 'dev-mode', { duration: duration } ).then( function ( payload ) {
			applyState( payload );
			toast( 'off' === duration ? t( 'toast_dev_off' ) : t( 'toast_dev_on' ), true );
		} ).catch( function () {
			busyButton( btn, false );
			toast( t( 'toast_error' ), false );
		} );
	}

	function doSave( btn ) {
		saving = true;
		busyButton( btn, true );
		updateSavebar();
		api( 'settings', model ).then( function ( payload ) {
			saving = false;
			applyState( payload );
			toast( t( 'toast_saved' ), true );
		} ).catch( function () {
			saving = false;
			busyButton( btn, false );
			updateSavebar();
			toast( t( 'toast_error' ), false );
		} );
	}

	/* --------------------------------------------------------------- events */

	root.addEventListener( 'click', function ( event ) {
		var target = event.target.closest( '[data-action]' );
		if ( ! target ) {
			return;
		}

		switch ( target.dataset.action ) {
			case 'purge':
				doPurge( target );
				break;
			case 'warm':
				doWarm( target );
				break;
			case 'dev':
				doDevMode( target, target.dataset.duration );
				break;
			case 'save':
				doSave( target );
				break;
			case 'discard':
				model = JSON.parse( JSON.stringify( state.settings ) );
				render();
				break;
			case 'promo-dismiss':
				try {
					window.localStorage.setItem( 'reallycache-promo-dismissed', '1' );
				} catch ( e ) {}
				var promo = el( 'promo' );
				if ( promo ) {
					promo.remove();
				}
				break;
		}
	} );

	root.addEventListener( 'change', function ( event ) {
		var field = event.target.dataset && event.target.dataset.field;
		if ( ! field || ! model ) {
			return;
		}

		if ( 'checkbox' === event.target.type ) {
			model[ field ] = event.target.checked;
		} else if ( 'ttl' === field ) {
			model.ttl = parseInt( event.target.value, 10 );
		} else {
			model[ field ] = event.target.value.split( '\n' ).map( function ( line ) {
				return line.trim();
			} ).filter( Boolean );
		}

		updateSavebar();
	} );

	root.addEventListener( 'input', function ( event ) {
		if ( event.target.classList && event.target.classList.contains( 'rc-textarea' ) ) {
			var field = event.target.dataset.field;
			model[ field ] = event.target.value.split( '\n' ).map( function ( line ) {
				return line.trim();
			} ).filter( Boolean );
			updateSavebar();
		}
	} );

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( isDirty() ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	/* ----------------------------------------------------------------- init */

	renderSkeleton();
	refresh();
} )();
