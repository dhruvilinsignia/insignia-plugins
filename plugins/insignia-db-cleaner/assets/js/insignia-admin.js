/**
 * Insignia DB Cleaner — admin screen behaviour.
 * No build step: plain ES2017, talks to admin-ajax.php.
 */
( function () {
	'use strict';

	if ( typeof window.InsigniaDBC === 'undefined' ) {
		return;
	}

	var CFG    = window.InsigniaDBC;
	var root   = document.getElementById( 'insignia-dbc-root' );
	if ( ! root ) {
		return;
	}

	var BATCH_SIZE = 200;

	/* ------------------------------------------------------------- *
	 * Small helpers
	 * ------------------------------------------------------------- */

	function qs( sel, ctx ) {
		return ( ctx || root ).querySelector( sel );
	}

	function qsa( sel, ctx ) {
		return Array.prototype.slice.call( ( ctx || root ).querySelectorAll( sel ) );
	}

	function post( action, data ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', CFG.nonce );

		Object.keys( data || {} ).forEach( function ( key ) {
			var value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( v ) { body.append( key + '[]', v ); } );
			} else if ( value && typeof value === 'object' ) {
				Object.keys( value ).forEach( function ( k ) {
					var v = value[ k ];
					if ( Array.isArray( v ) ) {
						v.forEach( function ( vv ) { body.append( key + '[' + k + '][]', vv ); } );
					} else {
						body.append( key + '[' + k + ']', v );
					}
				} );
			} else if ( value !== undefined && value !== null ) {
				body.set( key, value );
			}
		} );

		return fetch( CFG.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString(),
		} ).then( function ( res ) { return res.json(); } );
	}

	function toast( message, type ) {
		var wrap = document.getElementById( 'insignia-dbc-toasts' );
		if ( ! wrap ) {
			return;
		}
		var el = document.createElement( 'div' );
		el.className = 'insignia-toast insignia-toast--' + ( type || 'info' );
		el.innerHTML = '<span class="insignia-toast__icon">' + ( type === 'error' ? '!' : '✓' ) + '</span><span class="insignia-toast__msg"></span>';
		el.querySelector( '.insignia-toast__msg' ).textContent = message;
		el.addEventListener( 'click', function () { el.remove(); } );
		wrap.appendChild( el );
		setTimeout( function () { el.remove(); }, 5000 );
	}

	function numberFmt( n ) {
		return Number( n || 0 ).toLocaleString();
	}

	/* ------------------------------------------------------------- *
	 * Custom confirm modal (replaces the native browser confirm())
	 * ------------------------------------------------------------- */

	function insigniaConfirm( message, options ) {
		options = options || {};

		return new Promise( function ( resolve ) {
			var overlay = document.createElement( 'div' );
			overlay.className = 'insignia-prompt-overlay';

			var box = document.createElement( 'div' );
			box.className = 'insignia-prompt';
			box.setAttribute( 'role', 'alertdialog' );
			box.setAttribute( 'aria-modal', 'true' );

			var title = document.createElement( 'h3' );
			title.textContent = options.title || 'Delete these items?';

			var body = document.createElement( 'p' );
			body.style.cssText = 'color:var(--insignia-text-muted);font-size:14px;line-height:1.6;margin:0;';
			body.textContent = message;

			var actions = document.createElement( 'div' );
			actions.className = 'insignia-prompt__actions';

			var cancelBtn = document.createElement( 'button' );
			cancelBtn.type = 'button';
			cancelBtn.className = 'insignia-btn insignia-btn--ghost';
			cancelBtn.textContent = options.cancelLabel || 'Cancel';

			var okBtn = document.createElement( 'button' );
			okBtn.type = 'button';
			okBtn.className = 'insignia-btn insignia-btn--danger';
			okBtn.textContent = options.okLabel || 'Delete';

			actions.appendChild( cancelBtn );
			actions.appendChild( okBtn );
			box.appendChild( title );
			box.appendChild( body );
			box.appendChild( actions );
			overlay.appendChild( box );
			document.body.appendChild( overlay );

			function close( result ) {
				document.removeEventListener( 'keydown', onKeydown );
				overlay.remove();
				resolve( result );
			}

			function onKeydown( e ) {
				if ( e.key === 'Escape' ) close( false );
				if ( e.key === 'Enter' ) close( true );
			}

			overlay.addEventListener( 'click', function ( e ) {
				if ( e.target === overlay ) close( false );
			} );
			cancelBtn.addEventListener( 'click', function () { close( false ); } );
			okBtn.addEventListener( 'click', function () { close( true ); } );
			document.addEventListener( 'keydown', onKeydown );

			okBtn.focus();
		} );
	}

	function bytesFmt( bytes ) {
		bytes = Math.max( 0, Number( bytes ) || 0 );
		var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		var i = 0;
		while ( bytes >= 1024 && i < units.length - 1 ) {
			bytes /= 1024;
			i++;
		}
		return ( i === 0 ? Math.round( bytes ) : bytes.toFixed( 2 ) ) + ' ' + units[ i ];
	}

	/* ------------------------------------------------------------- *
	 * Tabs
	 * ------------------------------------------------------------- */

	function initTabs() {
		qsa( '.insignia-tab' ).forEach( function ( tab ) {
			tab.addEventListener( 'click', function () {
				var target = tab.getAttribute( 'data-insignia-tab' );

				qsa( '.insignia-tab' ).forEach( function ( t ) { t.classList.remove( 'is-active' ); } );
				tab.classList.add( 'is-active' );

				qsa( '.insignia-panel' ).forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-insignia-panel' ) !== target;
				} );
			} );
		} );
	}

	/* ------------------------------------------------------------- *
	 * Rendering scan results into the existing server-rendered DOM
	 * ------------------------------------------------------------- */

	var lastReport = null;

	function renderReport( payload ) {
		lastReport = payload;
		var report = payload.report;
		var db     = payload.database;

		var statCount  = document.getElementById( 'insignia-dbc-stat-count' );
		var statSize   = document.getElementById( 'insignia-dbc-stat-size' );
		var statTables = document.getElementById( 'insignia-dbc-stat-tables' );
		var statDbSize = document.getElementById( 'insignia-dbc-stat-dbsize' );
		var cleanupCnt = document.getElementById( 'insignia-dbc-cleanup-count' );
		var cleanupSum = document.getElementById( 'insignia-dbc-cleanup-summary' );

		if ( statCount ) statCount.textContent = numberFmt( report.totals.count );
		if ( statSize ) statSize.textContent = bytesFmt( report.totals.size );
		if ( statTables ) statTables.textContent = numberFmt( db.table_count );
		if ( statDbSize ) statDbSize.textContent = db.total_size_human;
		if ( cleanupCnt ) cleanupCnt.textContent = numberFmt( report.totals.count );
		if ( cleanupSum ) cleanupSum.textContent = numberFmt( report.totals.count ) + ' items · ' + bytesFmt( report.totals.size ) + ' reclaimable';

		// Per-row updates.
		var groupTotals = {};
		report.items.forEach( function ( item ) {
			var row = qs( 'tr[data-item="' + item.key + '"]' );
			if ( row ) {
				var countCell = row.querySelector( '.insignia-dbc-cell-count' );
				var sizeCell  = row.querySelector( '.insignia-dbc-cell-size' );
				var checkbox  = row.querySelector( '.insignia-dbc-row-check' );
				var cleanBtn  = row.querySelector( '.insignia-dbc-clean-item' );

				if ( countCell ) countCell.textContent = numberFmt( item.count );
				if ( sizeCell ) sizeCell.textContent = item.size_human;
				row.classList.toggle( 'is-active', item.count > 0 );

				if ( item.count === 0 ) {
					if ( checkbox ) { checkbox.checked = false; checkbox.disabled = true; }
					if ( cleanBtn ) cleanBtn.disabled = true;
				} else {
					if ( checkbox ) checkbox.disabled = false;
					if ( cleanBtn ) cleanBtn.disabled = false;
				}
			}

			groupTotals[ item.group ] = groupTotals[ item.group ] || { count: 0, size: 0 };
			groupTotals[ item.group ].count += item.count;
			groupTotals[ item.group ].size  += item.size;
		} );

		// Per-group cards.
		qsa( '[data-insignia-group]' ).forEach( function ( card ) {
			var group  = card.getAttribute( 'data-insignia-group' );
			var totals = groupTotals[ group ] || { count: 0, size: 0 };
			var status = card.querySelector( '.insignia-card__status' );
			var sizeEl = card.querySelector( '.insignia-meta-item' );
			var btn    = card.querySelector( '.insignia-dbc-clean-group' );

			if ( status ) {
				status.classList.toggle( 'is-on', totals.count > 0 );
				status.classList.toggle( 'is-off', totals.count === 0 );
				status.lastChild.textContent = totals.count > 0
					? totals.count + ( totals.count === 1 ? ' item found' : ' items found' )
					: 'Clean';
			}
			if ( sizeEl ) sizeEl.lastChild.textContent = bytesFmt( totals.size );
			if ( btn ) btn.disabled = totals.count === 0;
		} );

		updateBulkBar();
	}

	function scanNow( silent ) {
		var btn = document.getElementById( 'insignia-dbc-rescan' );
		if ( btn ) btn.classList.add( 'is-busy' );

		return post( 'insignia_dbc_scan', {} ).then( function ( res ) {
			if ( btn ) btn.classList.remove( 'is-busy' );
			if ( res && res.success ) {
				renderReport( res.data );
				if ( ! silent ) toast( 'Scan complete.', 'success' );
			} else {
				toast( CFG.i18n.error, 'error' );
			}
			return res;
		} ).catch( function () {
			if ( btn ) btn.classList.remove( 'is-busy' );
			toast( CFG.i18n.error, 'error' );
		} );
	}

	/* ------------------------------------------------------------- *
	 * Cleaning — batches a single item until nothing is left
	 * ------------------------------------------------------------- */

	function cleanItem( key, onProgress ) {
		function step() {
			return post( 'insignia_dbc_clean_batch', { item: key, limit: BATCH_SIZE } ).then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( ( res && res.data && res.data.message ) || CFG.i18n.error );
				}
				if ( onProgress ) onProgress( res.data );
				if ( res.data.remaining > 0 ) {
					return step();
				}
				return res.data;
			} );
		}
		return step();
	}

	function cleanItems( keys ) {
		var queue = keys.slice();

		function next() {
			if ( ! queue.length ) {
				return Promise.resolve();
			}
			var key = queue.shift();
			return cleanItem( key ).then( next );
		}

		return next();
	}

	/* ------------------------------------------------------------- *
	 * Cleanup tab: selection + bulk bar
	 * ------------------------------------------------------------- */

	function selectedKeys() {
		return qsa( '.insignia-dbc-row-check:checked' ).map( function ( cb ) { return cb.value; } );
	}

	function updateBulkBar() {
		var bar   = document.getElementById( 'insignia-dbc-bulk-bar' );
		var count = document.getElementById( 'insignia-dbc-bulk-count' );
		var n     = selectedKeys().length;

		if ( ! bar || ! count ) return;
		count.textContent = n;
		bar.hidden = n === 0;
	}

	function initCleanupTab() {
		var selectAll = document.getElementById( 'insignia-dbc-select-all' );
		if ( selectAll ) {
			selectAll.addEventListener( 'change', function () {
				qsa( '.insignia-dbc-row-check:not(:disabled)' ).forEach( function ( cb ) {
					cb.checked = selectAll.checked;
				} );
				updateBulkBar();
			} );
		}

		root.addEventListener( 'change', function ( e ) {
			if ( e.target.classList.contains( 'insignia-dbc-row-check' ) ) {
				updateBulkBar();
			}
		} );

		root.addEventListener( 'click', function ( e ) {
			var itemBtn = e.target.closest( '.insignia-dbc-clean-item' );
			if ( itemBtn && ! itemBtn.disabled ) {
				runCleanAction( [ itemBtn.getAttribute( 'data-item' ) ], itemBtn );
				return;
			}

			var groupBtn = e.target.closest( '.insignia-dbc-clean-group' );
			if ( groupBtn && ! groupBtn.disabled ) {
				var group = groupBtn.getAttribute( 'data-group' );
				var keys  = ( lastReport ? lastReport.report.items : [] )
					.filter( function ( item ) { return item.group === group && item.count > 0; } )
					.map( function ( item ) { return item.key; } );
				if ( keys.length ) {
					runCleanAction( keys, groupBtn );
				}
				return;
			}

			var bulkBtn = e.target.closest( '#insignia-dbc-clean-selected' );
			if ( bulkBtn ) {
				var selected = selectedKeys();
				if ( selected.length ) {
					runCleanAction( selected, bulkBtn );
				}
			}
		} );
	}

	function runCleanAction( keys, triggerEl ) {
		insigniaConfirm( CFG.i18n.confirmClean, { title: 'Delete these items?', okLabel: 'Delete' } ).then( function ( confirmed ) {
			if ( ! confirmed ) {
				return;
			}

			var originalHtml = triggerEl ? triggerEl.innerHTML : '';
			if ( triggerEl ) {
				triggerEl.disabled = true;
				triggerEl.classList.add( 'is-busy' );
				triggerEl.innerHTML = '<span class="insignia-btn-spinner insignia-btn-spinner--xs"></span> ' + CFG.i18n.cleaning;
			}

			cleanItems( keys )
				.then( function () {
					toast( CFG.i18n.cleaned + '.', 'success' );
					return scanNow( true );
				} )
				.catch( function ( err ) {
					toast( ( err && err.message ) || CFG.i18n.error, 'error' );
				} )
				.then( function () {
					if ( triggerEl ) {
						triggerEl.classList.remove( 'is-busy' );
						triggerEl.innerHTML = originalHtml;
					}
				} );
		} );
	}

	/* ------------------------------------------------------------- *
	 * Optimize tab
	 * ------------------------------------------------------------- */

	function initOptimizeTab() {
		root.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '.insignia-dbc-optimize-item' );
			if ( btn ) {
				optimizeTable( btn.getAttribute( 'data-table' ), btn );
			}

			var allBtn = e.target.closest( '#insignia-dbc-optimize-all' );
			if ( allBtn ) {
				optimizeAll( allBtn );
			}
		} );
	}

	function optimizeTable( tableName, triggerEl ) {
		var original = triggerEl ? triggerEl.innerHTML : '';
		if ( triggerEl ) {
			triggerEl.disabled = true;
			triggerEl.innerHTML = '<span class="insignia-btn-spinner insignia-btn-spinner--xs"></span> ' + CFG.i18n.optimizing;
		}

		return post( 'insignia_dbc_optimize_table', { table: tableName } )
			.then( function ( res ) {
				if ( ! res || ! res.success ) {
					throw new Error( ( res && res.data && res.data.message ) || CFG.i18n.error );
				}
			} )
			.catch( function ( err ) {
				toast( ( err && err.message ) || CFG.i18n.error, 'error' );
			} )
			.then( function () {
				if ( triggerEl ) {
					triggerEl.disabled = false;
					triggerEl.innerHTML = original;
				}
			} );
	}

	function optimizeAll( triggerEl ) {
		var rows = qsa( '#insignia-dbc-optimize-rows tr[data-table]' );
		var original = triggerEl ? triggerEl.innerHTML : '';
		if ( triggerEl ) {
			triggerEl.disabled = true;
			triggerEl.innerHTML = '<span class="insignia-btn-spinner insignia-btn-spinner--xs"></span> ' + CFG.i18n.optimizing;
		}

		var chain = Promise.resolve();
		rows.forEach( function ( row ) {
			chain = chain.then( function () {
				return optimizeTable( row.getAttribute( 'data-table' ) );
			} );
		} );

		chain.then( function () {
			toast( CFG.i18n.optimized + '.', 'success' );
			if ( triggerEl ) {
				triggerEl.disabled = false;
				triggerEl.innerHTML = original;
			}
		} );
	}

	/* ------------------------------------------------------------- *
	 * Settings tab
	 * ------------------------------------------------------------- */

	function initSettingsTab() {
		var freqGroup = document.getElementById( 'insignia-dbc-automation-freq' );
		if ( freqGroup ) {
			qsa( '.insignia-seg', freqGroup ).forEach( function ( seg ) {
				seg.addEventListener( 'click', function () {
					qsa( '.insignia-seg', freqGroup ).forEach( function ( s ) { s.classList.remove( 'is-active' ); } );
					seg.classList.add( 'is-active' );
					freqGroup.setAttribute( 'data-value', seg.getAttribute( 'data-value' ) );
				} );
			} );
		}

		var form = document.getElementById( 'insignia-dbc-settings-form' );
		if ( ! form ) return;

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();

			var keepDays   = document.getElementById( 'insignia-dbc-keep-days' ).value;
			var automation = document.getElementById( 'insignia-dbc-automation-enabled' ).checked;
			var freq       = freqGroup ? freqGroup.getAttribute( 'data-value' ) : 'weekly';
			var items      = qsa( 'input[name="automation_items[]"]:checked' ).map( function ( cb ) { return cb.value; } );

			var submitBtn = document.getElementById( 'insignia-dbc-save-settings' );
			if ( submitBtn ) submitBtn.disabled = true;

			post( 'insignia_dbc_save_settings', {
				settings: {
					keep_days: keepDays,
					automation_enabled: automation ? 1 : 0,
					automation_freq: freq,
					automation_items: items,
				},
			} ).then( function ( res ) {
				if ( res && res.success ) {
					toast( CFG.i18n.saved, 'success' );
				} else {
					toast( CFG.i18n.error, 'error' );
				}
			} ).catch( function () {
				toast( CFG.i18n.error, 'error' );
			} ).then( function () {
				if ( submitBtn ) submitBtn.disabled = false;
			} );
		} );
	}

	/* ------------------------------------------------------------- *
	 * Boot
	 * ------------------------------------------------------------- */

	document.addEventListener( 'DOMContentLoaded', function () {
		initTabs();
		initCleanupTab();
		initOptimizeTab();
		initSettingsTab();

		var rescanBtn = document.getElementById( 'insignia-dbc-rescan' );
		if ( rescanBtn ) {
			rescanBtn.addEventListener( 'click', function () { scanNow(); } );
		}
	} );
} )();
