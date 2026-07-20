/**
 * api/download.js
 * Helpers for building REST URLs and calling the WPTD REST API.
 */

/**
 * Build the full download URL for a plugin or theme.
 */
export function buildDownloadUrl( type, slug ) {
        const { restUrl, nonce } = window.WPTDData;

        const url = new URL( restUrl + '/download' );
        url.searchParams.set( 'type', type );
        url.searchParams.set( 'slug', slug );
        url.searchParams.set( '_wpnonce', nonce );

        return url.toString();
}

export function triggerDownload( type, slug ) {
        const url = buildDownloadUrl( type, slug );
        const a = Object.assign( document.createElement( 'a' ), {
                href: url,
                download: slug + '.zip',
        } );
        document.body.appendChild( a );
        a.click();
        document.body.removeChild( a );
}

export async function bulkDownload( items ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/bulk-download', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( { items } ),
        } );

        if ( ! res.ok ) {
                let msg = 'Bulk download failed (' + res.status + ')';
                try { const err = await res.json(); msg = err?.message || msg; } catch ( _ ) {}
                throw new Error( msg );
        }

        return res.blob();
}

export function saveBlob( blob, filename ) {
        const url = URL.createObjectURL( blob );
        const a = Object.assign( document.createElement( 'a' ), {
                href: url,
                download: filename,
        } );
        document.body.appendChild( a );
        a.click();
        document.body.removeChild( a );
        setTimeout( () => URL.revokeObjectURL( url ), 30_000 );
}

export async function fetchDetails( type, slug ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/details' );
        url.searchParams.set( 'type', type );
        url.searchParams.set( 'slug', slug );

        const res = await fetch( url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        if ( ! res.ok ) throw new Error( 'Failed to load details (' + res.status + ')' );
        return res.json();
}

export async function fetchSettings() {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/settings', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        if ( ! res.ok ) throw new Error( 'Failed to load settings' );
        return res.json();
}

export async function saveSettings( patch ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/settings', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( patch ),
        } );
        if ( ! res.ok ) throw new Error( 'Failed to save settings' );
        return res.json();
}

export async function fetchHistory() {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/history', {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        if ( ! res.ok ) throw new Error( 'Failed to load history' );
        return res.json();
}

export async function addHistory( entry ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/history', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( entry ),
        } );
        if ( ! res.ok ) throw new Error( 'Failed to record history' );
        return res.json();
}

export async function clearHistory() {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/history', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        if ( ! res.ok ) throw new Error( 'Failed to clear history' );
        return res.json();
}

// ── File manager / code editor helpers ──────────────────────────────────────

async function jsonOrThrow( res, fallback ) {
        if ( ! res.ok ) {
                try {
                        const err = await res.json();
                        throw new Error( err?.error || err?.message || fallback );
                } catch ( e ) {
                        if ( e instanceof Error && e.message !== fallback ) throw e;
                        throw new Error( fallback );
                }
        }
        return res.json();
}

/**
 * GET /file-tree — nested tree of files inside a plugin/theme.
 */
export async function fetchFileTree( type, slug ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/file-tree' );
        url.searchParams.set( 'type', type );
        url.searchParams.set( 'slug', slug );
        const res = await fetch( url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Failed to load file tree' );
}

/**
 * GET /file — read a file's content + metadata.
 */
export async function readFile( type, slug, path ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/file' );
        url.searchParams.set( 'type', type );
        url.searchParams.set( 'slug', slug );
        url.searchParams.set( 'path', path );
        const res = await fetch( url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Failed to read file' );
}

/**
 * POST /file — save file content.
 */
export async function writeFile( type, slug, path, content ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/file', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( { type, slug, path, content } ),
        } );
        return jsonOrThrow( res, 'Failed to save file' );
}

/**
 * DELETE /file — delete a file or directory.
 */
export async function deleteFile( type, slug, path ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/file' );
        url.searchParams.set( 'type', type );
        url.searchParams.set( 'slug', slug );
        url.searchParams.set( 'path', path );
        const res = await fetch( url.toString(), {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Failed to delete file' );
}

/**
 * POST /file/create — create a new file or directory.
 */
export async function createFile( type, slug, path, isDir = false ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/file/create', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( { type, slug, path, isDir } ),
        } );
        return jsonOrThrow( res, 'Failed to create file' );
}

/**
 * POST /file/rename — rename a file or directory.
 */
export async function renameFile( type, slug, path, newName ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/file/rename', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( { type, slug, path, newName } ),
        } );
        return jsonOrThrow( res, 'Failed to rename file' );
}

/**
 * GET /file/download — download a single file (raw) or a folder (as ZIP)
 * directly from the server. The server decides what to send based on
 * whether the resolved path is a file or a directory.
 *
 * This builds the URL and triggers a normal browser download (no fetch),
 * so the browser handles the Save-As dialog and progress natively.
 */
export function buildFileDownloadUrl( type, slug, path ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/file/download' );
        url.searchParams.set( 'type', type );
        if ( slug ) {
                url.searchParams.set( 'slug', slug );
        }
        if ( path ) {
                url.searchParams.set( 'path', path );
        }
        url.searchParams.set( '_wpnonce', nonce );
        return url.toString();
}

/**
 * Trigger a download for an arbitrary path (file or folder) inside the
 * currently browsed plugin / theme / WordPress root.
 *
 * @param {string}  type  'plugin' | 'theme' | 'wordpress'
 * @param {string}  slug  Plugin or theme slug (empty for wordpress).
 * @param {string}  path  Relative path inside the root.
 * @param {string}  filename  Suggested filename for the Save-As dialog.
 *                            Use `<folder>.zip` for folders, real name for files.
 */
export function triggerFileDownload( type, slug, path, filename ) {
        const url = buildFileDownloadUrl( type, slug, path );
        const a = Object.assign( document.createElement( 'a' ), {
                href: url,
                download: filename || 'download',
        } );
        document.body.appendChild( a );
        a.click();
        document.body.removeChild( a );
}

/**
 * GET /search — grep-like global search across a plugin/theme.
 */
export async function searchCode( type, slug, query, { caseSensitive = false, regex = false } = {} ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/search' );
        url.searchParams.set( 'type', type );
        url.searchParams.set( 'slug', slug );
        url.searchParams.set( 'q', query );
        url.searchParams.set( 'caseSensitive', caseSensitive ? '1' : '0' );
        url.searchParams.set( 'regex', regex ? '1' : '0' );
        const res = await fetch( url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Search failed' );
}

/**
 * POST /lint — scan a saved file PLUS every other PHP file in the same
 * root, returning syntax errors with line numbers and suggested fixes.
 *
 * @param {string} type 'plugin' | 'theme' | 'wordpress'
 * @param {string} slug Plugin or theme slug (empty for wordpress).
 * @param {string} path Relative path of the file that was just saved.
 * @param {boolean} deep When true, also lint every other PHP file in the
 *                       same root so we can surface "connected errors".
 *                       Defaults to false (the deep scan was causing
 *                       fatal errors on big plugins).
 * @returns {Promise<{
 *   savedFile: string,
 *   errors: Array<{file:string,line:number,column:number,message:string,solution:string,severity:string,isSavedFile:boolean}>,
 *   totalErrors: number,
 *   scannedFiles: number,
 *   savedFailed: boolean,
 *   hasCritical: boolean,
 *   hasWarning: boolean,
 *   engine: string,
 *   truncated: boolean
 * }>}
 */
export async function lintFile( type, slug, path, deep = false ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/lint', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( { type, slug, path, deep } ),
        } );
        return jsonOrThrow( res, 'Failed to lint file' );
}

/**
 * POST /lint-content — lint the NEW content of a file BEFORE it is saved
 * to disk. This is the "safe coding" gate: if the content has critical
 * PHP errors, the client can block the save entirely so the broken code
 * never reaches the live site.
 *
 * The backend writes the content to a temp file, lints it, and returns
 * the errors. The real file on disk is NOT modified.
 *
 * @param {string} type    'plugin' | 'theme' | 'wordpress'
 * @param {string} slug    Plugin or theme slug (empty for wordpress).
 * @param {string} path    Relative path of the file the content belongs to.
 * @param {string} content The NEW file content (not yet saved).
 * @returns {Promise<{
 *   savedFile: string,
 *   path: string,
 *   errors: Array<{file:string,line:number,column:number,message:string,solution:string,severity:string,isSavedFile:boolean}>,
 *   totalErrors: number,
 *   scannedFiles: number,
 *   savedFailed: boolean,
 *   hasCritical: boolean,
 *   hasWarning: boolean,
 *   engine: string,
 *   mode: string,
 *   truncated: boolean
 * }>}
 */
export async function lintContent( type, slug, path, content ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/lint-content', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( { type, slug, path, content } ),
        } );
        return jsonOrThrow( res, 'Failed to lint content' );
}

/**
 * POST /lint-deep — cross-file deep scan.
 *
 * Scans ALL PHP files in the same plugin/theme root for cross-file
 * issues:
 *   - Duplicate function names across files
 *   - Duplicate class/interface/trait names across files
 *
 * Uses the NEW content (if provided) for the saved file so it catches
 * duplicates the user is ABOUT to introduce before they reach disk.
 *
 * @param {string} type    'plugin' | 'theme' | 'wordpress'
 * @param {string} slug    Plugin or theme slug (empty for wordpress).
 * @param {string} path    Relative path of the saved file.
 * @param {string} content The NEW content of the saved file (optional —
 *                          if omitted, the on-disk content is used).
 * @returns {Promise<{
 *   savedFile: string,
 *   errors: Array<{file:string,line:number,column:number,message:string,solution:string,severity:string,isSavedFile:boolean}>,
 *   totalErrors: number,
 *   scannedFiles: number,
 *   hasCritical: boolean,
 *   hasWarning: boolean,
 *   engine: string,
 *   truncated: boolean
 * }>}
 */
export async function lintDeep( type, slug, path, content = null ) {
        const { restUrl, nonce } = window.WPTDData;
        const body = { type, slug, path };
        if ( content !== null ) {
                body.content = content;
        }
        const res = await fetch( restUrl + '/lint-deep', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': nonce,
                },
                body: JSON.stringify( body ),
        } );
        return jsonOrThrow( res, 'Failed to run deep lint scan' );
}

// ── Revision history & diffs ──────────────────────────────────────────────────

/**
 * GET /revisions — list recent file edits, optionally filtered by time.
 *
 * @param {number} hours Time window in hours (1, 2, 6, 24, …). 0 = all time.
 * @param {string} path  Optional: only show revisions for this file path.
 * @returns {Promise<{ revisions: Array, total: number, hours: number }>}
 */
export async function fetchRevisions( hours = 1, path = '' ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/revisions' );
        url.searchParams.set( 'hours', String( hours ) );
        if ( path ) {
                url.searchParams.set( 'path', path );
        }
        const res = await fetch( url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Failed to load revisions' );
}

/**
 * GET /diff?id=<revision-id> — git-style unified diff for one revision.
 *
 * @param {string} id Revision ID.
 * @returns {Promise<{ id:string, path:string, timestamp:number, diff:string, hunks:Array, stats:{additions:number,deletions:number} }>}
 */
export async function fetchDiff( id ) {
        const { restUrl, nonce } = window.WPTDData;
        const url = new URL( restUrl + '/diff' );
        url.searchParams.set( 'id', id );
        const res = await fetch( url.toString(), {
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Failed to load diff' );
}

/**
 * POST /revisions/<id>/restore — restore a file to its pre-edit state.
 *
 * @param {string} id Revision ID.
 * @returns {Promise<{ ok:boolean, path:string, size:number, message:string }>}
 */
export async function restoreRevision( id ) {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/revisions/' + encodeURIComponent( id ) + '/restore', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Failed to restore revision' );
}

/**
 * DELETE /revisions — clear all revision history.
 */
export async function clearRevisions() {
        const { restUrl, nonce } = window.WPTDData;
        const res = await fetch( restUrl + '/revisions', {
                method: 'DELETE',
                credentials: 'same-origin',
                headers: { 'X-WP-Nonce': nonce },
        } );
        return jsonOrThrow( res, 'Failed to clear revisions' );
}
