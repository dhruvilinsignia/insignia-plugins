/**
 * utils.js — small shared helpers.
 */

export function formatSize( bytes ) {
	if ( ! bytes || bytes <= 0 ) return '0 B';
	const units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
	const i = Math.min( Math.floor( Math.log( bytes ) / Math.log( 1024 ) ), units.length - 1 );
	return ( bytes / Math.pow( 1024, i ) ).toFixed( i === 0 ? 0 : 1 ) + ' ' + units[ i ];
}

export function formatDate( ts ) {
	if ( ! ts ) return '—';
	const d = new Date( ts * 1000 );
	return d.toLocaleString( undefined, {
		year: 'numeric', month: 'short', day: 'numeric',
		hour: '2-digit', minute: '2-digit',
	} );
}

export function timeAgo( ts ) {
	if ( ! ts ) return '';
	const diff = Date.now() / 1000 - ts;
	if ( diff < 60 ) return 'just now';
	if ( diff < 3600 ) return Math.floor( diff / 60 ) + 'm ago';
	if ( diff < 86400 ) return Math.floor( diff / 3600 ) + 'h ago';
	if ( diff < 604800 ) return Math.floor( diff / 86400 ) + 'd ago';
	return formatDate( ts );
}

export function initials( name ) {
	return ( name || '' )
		.split( /[\s_-]+/ )
		.filter( Boolean )
		.slice( 0, 2 )
		.map( ( w ) => w[ 0 ] )
		.join( '' )
		.toUpperCase();
}

/**
 * Deterministic color from a string slug — used for avatar gradients.
 * All palettes lean into the light-purple brand.
 */
export function colorFromSlug( slug ) {
	const palette = [
		[ '#8b5cf6', '#a78bfa' ], // violet → light violet
		[ '#a855f7', '#c084fc' ], // purple → light purple
		[ '#7c3aed', '#8b5cf6' ], // deep violet → violet
		[ '#6d28d9', '#7c3aed' ], // dark violet
		[ '#9333ea', '#a855f7' ], // rich purple
		[ '#c084fc', '#d8b4fe' ], // light purple → soft lilac
	];
	let hash = 0;
	for ( let i = 0; i < slug.length; i++ ) {
		hash = ( hash << 5 ) - hash + slug.charCodeAt( i );
		hash |= 0;
	}
	const idx = Math.abs( hash ) % palette.length;
	return palette[ idx ];
}

export function uid() {
	return Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 8 );
}

// ── File extension → CodeMirror mode + emoji icon ───────────────────────────

const EXT_TO_MODE = {
	php:    'php',
	php3:   'php',
	php4:   'php',
	php5:   'php',
	phtml:  'php',
	js:     'javascript',
	mjs:    'javascript',
	jsx:    'javascript',
	ts:     'javascript',
	tsx:    'javascript',
	vue:    'javascript',
	css:    'css',
	scss:   'css',
	less:   'css',
	sass:   'css',
	html:   'htmlmixed',
	htm:    'htmlmixed',
	xml:    'xml',
	svg:    'xml',
	json:   'application/json',
	geojson:'application/json',
	md:     'markdown',
	markdown: 'markdown',
	txt:    'text/plain',
	yml:    'yaml',
	yaml:   'yaml',
	sql:    'sql',
	htaccess: 'text/plain',
	env:    'text/plain',
	gitignore: 'text/plain',
	mo:     'text/plain',
	po:     'text/plain',
};

export function extToMode( ext ) {
	return EXT_TO_MODE[ ( ext || '' ).toLowerCase() ] || 'text/plain';
}

const FILE_ICONS = {
	php: '🐘',
	js: '📜',
	ts: '📜',
	jsx: '⚛',
	tsx: '⚛',
	css: '🎨',
	scss: '🎨',
	sass: '🎨',
	less: '🎨',
	html: '🌐',
	htm: '🌐',
	xml: '🌐',
	svg: '🖼',
	json: '⚙',
	md: '📖',
	markdown: '📖',
	txt: '📄',
	yml: '⚙',
	yaml: '⚙',
	sql: '🗄',
	png: '🖼',
	jpg: '🖼',
	jpeg: '🖼',
	gif: '🖼',
	woff: '🔤',
	woff2: '🔤',
	ttf: '🔤',
	po: '🌐',
	mo: '🌐',
	htaccess: '⚙',
	env: '🔧',
	gitignore: '⚙',
};

export function fileIcon( ext ) {
	return FILE_ICONS[ ( ext || '' ).toLowerCase() ] || '📄';
}

export function basename( path ) {
	if ( ! path ) return '';
	const parts = String( path ).split( /[/\\]/ );
	return parts[ parts.length - 1 ];
}

export function dirname( path ) {
	if ( ! path ) return '';
	const parts = String( path ).split( /[/\\]/ );
	parts.pop();
	return parts.join( '/' );
}

export function joinPath( base, sub ) {
	if ( ! base ) return sub || '';
	if ( ! sub ) return base;
	return base.replace( /\/+$/, '' ) + '/' + sub.replace( /^\/+/, '' );
}
