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

	// Plain-text byte label (formatBytes returns HTML with <small>).
	function bytesLabel( bytes ) {
		if ( ! bytes ) {
			return '0 KB';
		}
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i     = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
		var value = bytes / Math.pow( 1024, i );
		return ( value >= 100 ? Math.round( value ) : value.toFixed( 1 ) ) + ' ' + units[ i ];
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
			image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
			bulb: '<path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-4 12.7c.6.5 1 1.4 1 2.3h6c0-.9.4-1.8 1-2.3A7 7 0 0 0 12 2z"/>',
			spinner: '<path d="M21 12a9 9 0 1 1-6.2-8.6"/>'
		};
		return '<svg class="' + ( cls || '' ) + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths[ name ] + '</svg>';
	}

	/* ---------------------------------------------------------------- state */

	var state = null;   // Last payload from the server.
	var model = null;   // The settings being edited.
	var saving = false;
	var devTimer = null;

	var activeTab = 'cache';
	try {
		activeTab = window.localStorage.getItem( 'reallycache-tab' ) || 'cache';
	} catch ( e ) {}

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
		var banners = '';

		if ( ! state.dropin ) {
			banners += '<div class="rc-banner rc-banner--warn">' + icon( 'warn' ) +
				'<div class="rc-banner-text"><strong>' + esc( t( 'dropin_warn_title' ) ) + '</strong><br>' + esc( t( 'dropin_warn_body' ) ) + '</div>' +
			'</div>';
		} else if ( false === state.dropin_ours ) {
			banners += '<div class="rc-banner rc-banner--warn">' + icon( 'warn' ) +
				'<div class="rc-banner-text"><strong>' + esc( t( 'dropin_foreign_title' ) ) + '</strong><br>' + esc( t( 'dropin_foreign_body' ) ) + '</div>' +
			'</div>';
		}

		if ( state.health && ! state.health.cache_writable ) {
			banners += '<div class="rc-banner rc-banner--danger">' + icon( 'warn' ) +
				'<div class="rc-banner-text"><strong>' + esc( t( 'health_write_title' ) ) + '</strong><br>' + esc( tf( 'health_write_body', state.health.cache_root ) ) + '</div>' +
			'</div>';
		}

		return banners;
	}

	function pathOf( url ) {
		try {
			var u = new URL( url, window.location.origin );
			return decodeURIComponent( u.pathname ) || '/';
		} catch ( e ) {
			return url || '…';
		}
	}

	function warmbarHtml() {
		var w = state.warm;
		if ( ! w || ! w.running ) {
			return '';
		}
		var pct = w.total ? Math.round( ( w.done / w.total ) * 100 ) : 0;

		return '<div class="rc-progress-card" data-el="warmbar">' +
			'<div class="rc-progress-row">' +
				'<span class="rc-progress-label">' + esc( t( 'warm_now' ) ) + ' <strong data-el="warm-current">' + esc( pathOf( w.current ) ) + '</strong></span>' +
				'<span class="rc-progress-count" data-el="warm-count">' + w.done + '/' + w.total + '</span>' +
				'<button type="button" class="rc-progress-stop" data-action="warm-stop" title="' + esc( t( 'btn_stop_warm' ) ) + '">' + icon( 'x' ) + '<span>' + esc( t( 'btn_stop' ) ) + '</span></button>' +
			'</div>' +
			'<div class="rc-bar"><div class="rc-bar-fill" data-el="warm-fill" style="width:' + pct + '%"></div></div>' +
		'</div>';
	}

	function webpDonut( w ) {
		var pct    = w.library_total ? Math.round( ( w.library_converted / w.library_total ) * 100 ) : 0;
		var circ   = 97.4; // 2πr for r=15.5
		var offset = ( circ * ( 100 - pct ) ) / 100;

		return '<div class="rc-donut-wrap">' +
			'<svg class="rc-donut" viewBox="0 0 36 36" aria-hidden="true">' +
				'<circle class="rc-donut-track" cx="18" cy="18" r="15.5"></circle>' +
				'<circle class="rc-donut-fill" data-el="webp-donut" cx="18" cy="18" r="15.5" stroke-dasharray="' + circ + '" stroke-dashoffset="' + offset + '"></circle>' +
			'</svg>' +
			'<span class="rc-donut-label" data-el="webp-pct">' + pct + '%</span>' +
		'</div>';
	}

	function mediaHtml() {
		var w = state.webp;
		if ( ! w ) {
			return '';
		}

		var body = '';
		if ( ! w.supported ) {
			body = '<div class="rc-row"><div class="rc-row-text"><div class="rc-row-desc">' + esc( t( 'webp_unsupported' ) ) + '</div></div></div>';
		} else {
			var allDone = w.library_total > 0 && w.library_converted >= w.library_total;

			body = switchRow( 'webp_enabled', t( 'row_webp' ), t( 'row_webp_desc' ) ) +
				switchRow( 'webp_on_upload', t( 'row_webp_upload' ), t( 'row_webp_upload_desc' ) ) +
				switchRow( 'lcp_boost', t( 'row_lcp' ), t( 'row_lcp_desc' ) ) +
				switchRow( 'font_display_swap', t( 'row_fonts' ), t( 'row_fonts_desc' ) ) +
				switchRow( 'datauri_extract', t( 'row_datauri' ), t( 'row_datauri_desc' ) ) +
				'<div class="rc-media-stats">' +
					webpDonut( w ) +
					'<div class="rc-media-stat">' + esc( t( 'webp_converted' ) ) + '<strong><span data-el="webp-libdone">' + esc( formatNumber( w.library_converted ) ) + '</span> / ' + esc( formatNumber( w.library_total ) ) + '</strong></div>' +
					'<div class="rc-media-stat">' + esc( t( 'webp_saved' ) ) + '<strong data-el="webp-saved">' + formatBytes( w.saved ) + '</strong></div>' +
					'<div class="rc-media-stat"' + ( w.pending ? '' : ' hidden' ) + '>' + esc( t( 'webp_pending' ) ) + '<strong data-el="webp-pending">' + esc( formatNumber( w.pending ) ) + '</strong></div>' +
				'</div>';

			if ( w.cache_stale && ! w.running && state.stats.pages > 0 ) {
				body += '<div class="rc-banner rc-banner--warn rc-banner--slim">' + icon( 'warn' ) +
					'<div class="rc-banner-text">' + esc( t( 'webp_stale_note' ) ) + '</div>' +
					'<button type="button" class="rc-btn rc-btn--primary rc-btn--sm" data-action="purge">' + esc( t( 'btn_clear' ) ) + '</button>' +
				'</div>';
			}

			if ( ! w.running && ! allDone && w.estimate > 0 ) {
				var diskLow  = null !== w.disk_free && w.disk_free < w.estimate * 1.2;
				var diskNote = tf( 'webp_estimate', bytesLabel( w.estimate ) );
				if ( null !== w.disk_free ) {
					diskNote += ' · ' + tf( diskLow ? 'webp_disk_low' : 'webp_disk_free', bytesLabel( w.disk_free ) );
				}
				body += '<div class="rc-row-desc' + ( diskLow ? ' rc-text-danger' : '' ) + '" style="padding-block-start:2px">' + esc( diskNote ) + '</div>';
			}

			body += '<div class="rc-row" style="border:0">';
			if ( w.running ) {
				var pct = w.total ? Math.round( ( w.done / w.total ) * 100 ) : 0;
				body += '<div style="flex:1">' +
					'<div class="rc-progress-row">' +
						'<span class="rc-progress-label">' + esc( t( 'webp_now' ) ) + ' <strong data-el="webp-current">' + esc( w.current || '…' ) + '</strong></span>' +
						'<span class="rc-progress-count" data-el="webp-count">' + w.done + '/' + w.total + '</span>' +
					'</div>' +
					'<div class="rc-bar"><div class="rc-bar-fill" data-el="webp-fill" style="width:' + pct + '%"></div></div>' +
				'</div>';
			} else if ( allDone ) {
				body += '<button type="button" class="rc-btn rc-btn--ghost rc-btn--sm" data-action="webp-scan" data-force="1">' + icon( 'image' ) + '<span>' + esc( t( 'btn_webp_rescan' ) ) + '</span></button>';
			} else {
				body += '<button type="button" class="rc-btn rc-btn--ghost rc-btn--sm" data-action="webp-scan">' + icon( 'image' ) + '<span>' + esc( t( 'btn_webp_scan' ) ) + '</span></button>';
			}
			body += '</div>';
		}

		return '<div class="rc-card">' +
			'<div class="rc-card-head">' + icon( 'image' ) +
				'<span class="rc-card-title">' + esc( t( 'media_title' ) ) + '</span>' +
				'<span class="rc-card-desc">' + esc( t( 'media_desc' ) ) + '</span>' +
			'</div>' +
			'<div class="rc-card-body">' + body + '</div>' +
		'</div>';
	}

	function wooCartNote() {
		var v     = state.woo_cart_cache;
		var text  = null === v || undefined === v ? t( 'woo_cart_probing' ) : ( v ? t( 'woo_cart_ok' ) : t( 'woo_cart_safe' ) );
		var style = 'padding-block-end:10px' + ( true === v ? ';color:var(--rc-ok)' : '' );

		return '<div class="rc-row-desc" style="' + style + '">' + esc( text ) + '</div>';
	}

	function tabsHtml() {
		var tabs = [ [ 'cache', t( 'tab_cache' ) ], [ 'media', t( 'media_title' ) ] ];
		if ( state.redis && ( state.redis.available || state.redis.active ) ) {
			tabs.push( [ 'redis', 'Redis' ] );
		}
		if ( ! tabs.some( function ( tab ) { return tab[0] === activeTab; } ) ) {
			activeTab = 'cache';
		}

		return '<div class="rc-tabs" role="tablist">' + tabs.map( function ( tab ) {
			return '<button type="button" role="tab" class="rc-tab' + ( tab[0] === activeTab ? ' is-active' : '' ) + '" data-action="tab" data-tab="' + tab[0] + '">' + esc( tab[1] ) + '</button>';
		} ).join( '' ) + '</div>';
	}

	function activePanelHtml() {
		if ( 'media' === activeTab ) {
			return mediaHtml();
		}
		if ( 'redis' === activeTab ) {
			return redisHtml();
		}

		return devHtml() + settingsHtml();
	}

	function redisHtml() {
		var r = state.redis;
		if ( ! r || ( ! r.available && ! r.active ) ) {
			return '';
		}

		if ( r.foreign ) {
			return '<div class="rc-card"><div class="rc-card-head">' + icon( 'db' ) +
				'<span class="rc-card-title">Redis</span></div>' +
				'<div class="rc-card-body"><div class="rc-row"><div class="rc-row-text"><div class="rc-row-desc">' + esc( t( 'redis_foreign' ) ) + '</div></div></div></div></div>';
		}

		var ins  = r.active ? r.insights : null;
		var body = switchRow( 'redis_object_cache', t( 'row_redis' ), t( 'row_redis_desc' ) );

		if ( r.active && 'socket' === r.client ) {
			body += '<div class="rc-row-desc" style="padding-block-end:6px">' + esc( t( 'redis_socket_note' ) ) + '</div>';
		}

		if ( ins ) {
			body += '<div class="rc-media-stats">' +
				'<div class="rc-media-stat">' + esc( t( 'redis_hit_rate' ) ) + '<strong data-el="redis-rate">' + ins.hit_rate + '%</strong></div>' +
				'<div class="rc-media-stat">' + esc( t( 'redis_hits_label' ) ) + '<strong data-el="redis-hits">' + esc( formatNumber( ins.hits ) ) + '</strong></div>' +
				'<div class="rc-media-stat">' + esc( t( 'redis_keys_label' ) ) + '<strong data-el="redis-keys">' + esc( formatNumber( ins.keys ) ) + '</strong></div>' +
				'<div class="rc-media-stat">' + esc( t( 'redis_memory_label' ) ) + '<strong data-el="redis-mem">' + esc( ins.memory || '—' ) + '</strong></div>' +
				( ins.latency_us ? '<div class="rc-media-stat">' + esc( t( 'redis_latency_label' ) ) + '<strong>' + esc( String( ins.latency_us ) ) + 'µs</strong></div>' : '' ) +
			'</div>';

			var chips = Object.keys( ins.groups || {} ).map( function ( group ) {
				return '<span class="rc-chip">' + esc( group ) + ' · ' + esc( formatNumber( ins.groups[ group ] ) ) + '</span>';
			} ).join( '' );
			if ( chips ) {
				body += '<div class="rc-row-desc" style="padding-block-start:4px">' + esc( t( 'redis_groups_title' ) ) + '</div>' +
					'<div class="rc-chips">' + chips + '</div>';
			}
		}

		if ( r.active ) {
			var ttlOptions = [ 0, 3600, 21600, 86400 ].map( function ( s ) {
				var label = 0 === s ? t( 'ttl_unlimited' ) : ttlLabel( s );
				return '<option value="' + s + '"' + ( model.redis_max_ttl === s ? ' selected' : '' ) + '>' + esc( label ) + '</option>';
			} ).join( '' );

			body += '<div class="rc-row">' +
					'<div class="rc-row-text"><div class="rc-row-title">' + esc( t( 'row_redis_ttl' ) ) + '</div>' +
						'<div class="rc-row-desc">' + esc( t( 'row_redis_ttl_desc' ) ) + '</div></div>' +
					'<select class="rc-select" data-field="redis_max_ttl">' + ttlOptions + '</select>' +
				'</div>' +
				'<div class="rc-row rc-row--col">' +
					'<div class="rc-row-text"><div class="rc-row-title">' + esc( t( 'row_redis_groups' ) ) + '</div>' +
						'<div class="rc-row-desc">' + esc( t( 'row_redis_groups_desc' ) ) + '</div></div>' +
					'<textarea class="rc-textarea" data-field="redis_excluded_groups" spellcheck="false" rows="3">' + esc( model.redis_excluded_groups.join( '\n' ) ) + '</textarea>' +
				'</div>' +
				'<div class="rc-row" style="border:0">' +
					'<button type="button" class="rc-btn rc-btn--ghost rc-btn--sm" data-action="redis-flush">' + icon( 'trash' ) + '<span>' + esc( t( 'btn_redis_flush' ) ) + '</span></button>' +
				'</div>';
		}

		return '<div class="rc-card">' +
			'<div class="rc-card-head">' + icon( 'db' ) +
				'<span class="rc-card-title">Redis</span>' +
				'<span class="rc-card-desc">' + esc( t( 'redis_desc' ) ) + '</span>' +
			'</div>' +
			'<div class="rc-card-body">' + body + '</div>' +
		'</div>';
	}

	function snippetBlock( title, snippetKey ) {
		var code = ( RC.snippets && RC.snippets[ snippetKey ] ) || '';

		return '<div class="rc-snippet">' +
			'<div class="rc-snippet-head"><span>' + esc( title ) + '</span>' +
				'<button type="button" class="rc-btn rc-btn--subtle rc-btn--sm" data-action="copy" data-copy="' + esc( snippetKey ) + '">' + esc( t( 'btn_copy' ) ) + '</button></div>' +
			'<pre dir="ltr">' + esc( code ) + '</pre>' +
		'</div>';
	}

	function recsModalHtml() {
		return '<div class="rc-modal-overlay" data-el="recs" hidden>' +
			'<div class="rc-modal" role="dialog" aria-modal="true">' +
				'<div class="rc-modal-head">' +
					'<span class="rc-card-title">' + esc( t( 'recs_title' ) ) + '</span>' +
					'<button type="button" class="rc-promo-close" data-action="recs-close" aria-label="' + esc( t( 'promo_dismiss' ) ) + '">' + icon( 'x' ) + '</button>' +
				'</div>' +
				'<p class="rc-row-desc">' + esc( t( 'recs_intro' ) ) + '</p>' +
				snippetBlock( t( 'recs_bcache' ), 'browser_cache' ) +
				snippetBlock( t( 'recs_gzip' ), 'gzip' ) +
			'</div>' +
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
				'<div class="rc-row">' +
					'<div class="rc-row-text"><div class="rc-row-title">' + esc( t( 'row_warm_max' ) ) + '</div>' +
						'<div class="rc-row-desc">' + esc( t( 'row_warm_max_desc' ) ) + '</div></div>' +
					'<select class="rc-select" data-field="warm_max">' +
						[ 500, 2000, 10000, 0 ].map( function ( n ) {
							var label = 0 === n ? t( 'warm_max_all' ) : tf( 'warm_max_pages', formatNumber( n ) );
							return '<option value="' + n + '"' + ( model.warm_max === n ? ' selected' : '' ) + '>' + esc( label ) + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				switchRow( 'daily_warm', t( 'row_daily' ), t( 'row_daily_desc' ) ) +
				'<div class="rc-row">' +
					'<div class="rc-row-text"><div class="rc-row-title">' + esc( t( 'row_daily_hour' ) ) + '</div>' +
						'<div class="rc-row-desc">' + esc( t( 'row_daily_hour_desc' ) ) + '</div></div>' +
					'<select class="rc-select" data-field="daily_warm_hour">' +
						Array.from( { length: 24 }, function ( _, h ) {
							var label = ( h < 10 ? '0' + h : String( h ) ) + ':00';
							return '<option value="' + h + '"' + ( model.daily_warm_hour === h ? ' selected' : '' ) + '>' + label + '</option>';
						} ).join( '' ) +
					'</select>' +
				'</div>' +
				( state.woo_active ? switchRow( 'woocommerce_protection', t( 'row_woo' ), t( 'row_woo_desc' ) ) + wooCartNote() : '' ) +
				switchRow( 'browser_cache', t( 'row_bcache' ), t( 'row_bcache_desc' ) ) +
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
					( 'nginx' === RC.server
						? '<button type="button" class="rc-btn rc-btn--warn" data-action="recs-open">' + icon( 'bulb' ) + '<span>' + esc( t( 'btn_server_recs' ) ) + '</span></button>'
						: '' ) +
					'<button type="button" class="rc-btn rc-btn--ghost" data-action="warm"' + ( state.warm && state.warm.running ? ' disabled' : '' ) + '>' + icon( 'flame' ) + '<span data-el="warm-label">' + esc( warmLabel() ) + '</span></button>' +
					'<button type="button" class="rc-btn rc-btn--ghost" data-action="purge">' + icon( 'trash' ) + '<span>' + esc( t( 'btn_clear' ) ) + '</span></button>' +
				'</div>' +
				'<div class="rc-main">' +
					bannerHtml() +
					warmbarHtml() +
					tilesHtml() +
					tabsHtml() +
					activePanelHtml() +
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
			recsModalHtml() +
			'<div class="rc-toasts" data-el="toasts" aria-live="polite"></div>';

		updateSavebar();
		scheduleDevTick();
		schedulePoll();
		scheduleProbeRefresh();
	}

	// The cart probe runs itself in the background on first load; while
	// its verdict is pending, refresh a few times so it appears without
	// any user action.
	var probeTimer = null;
	var probeTries = 0;

	function scheduleProbeRefresh() {
		if ( probeTimer ) {
			clearTimeout( probeTimer );
			probeTimer = null;
		}
		if ( ! state.woo_active || null != state.woo_cart_cache || probeTries >= 5 ) {
			return;
		}
		probeTries ++;
		probeTimer = setTimeout( function () {
			api( 'state' ).then( function ( payload ) {
				if ( ! isDirty() && ! saving ) {
					applyState( payload );
				} else {
					state.woo_cart_cache = payload.woo_cart_cache;
					scheduleProbeRefresh();
				}
			} ).catch( scheduleProbeRefresh );
		}, 7000 );
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

	// While a warm-up or conversion runs, poll for progress every 2s. Each
	// poll also pumps the server-side queues. A full re-render would blow
	// away unsaved edits, so with a dirty form only the live parts are
	// patched in place.
	var pollTimer = null;

	function isBusy() {
		return ( state.warm && state.warm.running ) || ( state.webp && state.webp.running );
	}

	function schedulePoll() {
		if ( pollTimer ) {
			clearTimeout( pollTimer );
			pollTimer = null;
		}
		if ( state && isBusy() ) {
			pollTimer = setTimeout( pollStatus, 2000 );
		}
	}

	function setText( name, value ) {
		var node = el( name );
		if ( node ) {
			node.textContent = value;
		}
	}

	function setBar( name, done, total ) {
		var node = el( name );
		if ( node ) {
			node.style.width = ( total ? Math.round( ( done / total ) * 100 ) : 0 ) + '%';
		}
	}

	function patchProgress() {
		if ( state.warm ) {
			setText( 'warm-label', warmLabel() );
			setText( 'warm-current', pathOf( state.warm.current ) );
			setText( 'warm-count', state.warm.done + '/' + state.warm.total );
			setBar( 'warm-fill', state.warm.done, state.warm.total );
			var bar = el( 'warmbar' );
			if ( bar && ! state.warm.running ) {
				bar.hidden = true;
			}
		}
		if ( state.webp ) {
			setText( 'webp-current', state.webp.current || '…' );
			setText( 'webp-count', state.webp.done + '/' + state.webp.total );
			setBar( 'webp-fill', state.webp.done, state.webp.total );
			setText( 'webp-libdone', formatNumber( state.webp.library_converted ) );
			setText( 'webp-pending', formatNumber( state.webp.pending ) );
			var saved = el( 'webp-saved' );
			if ( saved ) {
				saved.innerHTML = formatBytes( state.webp.saved );
			}
			var pct = state.webp.library_total ? Math.round( ( state.webp.library_converted / state.webp.library_total ) * 100 ) : 0;
			setText( 'webp-pct', pct + '%' );
			var donut = el( 'webp-donut' );
			if ( donut ) {
				donut.setAttribute( 'stroke-dashoffset', ( 97.4 * ( 100 - pct ) ) / 100 );
			}
		}
		var pages = el( 'tile-pages' );
		if ( pages ) {
			pages.textContent = formatNumber( state.stats.pages );
		}
		var size = el( 'tile-size' );
		if ( size ) {
			size.innerHTML = formatBytes( state.stats.size );
		}
	}

	function pollStatus() {
		api( 'state' ).then( function ( payload ) {
			if ( isDirty() || saving ) {
				state.warm = payload.warm;
				state.webp = payload.webp;
				state.stats = payload.stats;
				patchProgress();
				schedulePoll();
			} else {
				applyState( payload );
			}
		} ).catch( schedulePoll );
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

	function doWebpScan( btn ) {
		busyButton( btn, true );
		api( 'webp-scan', { force: '1' === btn.dataset.force } ).then( function ( payload ) {
			applyState( payload );
			toast( t( 'toast_webp' ), true );
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
		var overlayHit = event.target.classList && event.target.classList.contains( 'rc-modal-overlay' );
		if ( overlayHit ) {
			event.target.hidden = true;
			return;
		}

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
			case 'webp-scan':
				doWebpScan( target );
				break;
			case 'tab':
				activeTab = target.dataset.tab;
				try {
					window.localStorage.setItem( 'reallycache-tab', activeTab );
				} catch ( e ) {}
				render();
				break;
			case 'redis-flush':
				busyButton( target, true );
				api( 'redis-flush', {} ).then( function ( payload ) {
					applyState( payload );
					toast( t( 'toast_redis_flushed' ), true );
				} ).catch( function () {
					busyButton( target, false );
					toast( t( 'toast_error' ), false );
				} );
				break;
			case 'recs-open': {
				var recs = el( 'recs' );
				if ( recs ) {
					recs.hidden = false;
				}
				break;
			}
			case 'recs-close': {
				var overlay = el( 'recs' );
				if ( overlay ) {
					overlay.hidden = true;
				}
				break;
			}
			case 'copy': {
				var text = ( RC.snippets && RC.snippets[ target.dataset.copy ] ) || '';
				if ( text && navigator.clipboard ) {
					navigator.clipboard.writeText( text ).then( function () {
						toast( t( 'toast_copied' ), true );
					} );
				}
				break;
			}
			case 'warm-stop':
				target.disabled = true;
				api( 'warm-stop', {} ).then( function ( payload ) {
					applyState( payload );
					toast( t( 'toast_warm_stopped' ), true );
				} ).catch( function () {
					target.disabled = false;
					toast( t( 'toast_error' ), false );
				} );
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
		} else if ( 'SELECT' === event.target.tagName ) {
			model[ field ] = parseInt( event.target.value, 10 );
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
