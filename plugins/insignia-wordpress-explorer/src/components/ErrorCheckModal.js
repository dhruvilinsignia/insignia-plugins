/**
 * ErrorCheckModal.js — popup that appears when the pre-save lint pass
 * finds PHP errors in the file content the user is about to save.
 *
 * Two modes (driven by the `hasCritical` flag in the lint result):
 *
 *   BLOCKING  (hasCritical === true)
 *     The file has at least one critical/fatal/parse error. Saving this
 *     content would break the live site, so the popup shows NO "Save
 *     Anyway" button — the user MUST fix the errors first. The only
 *     dismiss button is "Close" (which keeps the file unchanged).
 *
 *   WARNING   (hasCritical === false, hasWarning === true)
 *     The file only has warnings (notices, deprecations, soft warnings).
 *     These won't break the site, so the popup shows a "Save Anyway"
 *     button next to "Cancel". Clicking "Save Anyway" calls the
 *     `onSaveAnyway` callback (passed in from CodeEditor) which writes
 *     the pending content to disk.
 *
 * Layout
 * ──────
 *  Header  — title + file path + total error count + severity banner
 *  Body    — grouped list of errors. Each error row shows:
 *              • line:column badge (red for critical, yellow for warning)
 *              • error message
 *              • suggested fix block (highlighted)
 *              • "Open at line N" button (jumps to the file/line)
 *  Footer  — context-dependent:
 *              • Blocking → "Close" only + "Fix required" hint
 *              • Warning  → "Cancel" + "Save Anyway"
 *
 * The modal is centred on the screen via a flex overlay.
 */
import { useState, useEffect, useMemo } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Icon } from './Icon';

/**
 * Normalise a path to forward slashes and strip any leading slashes.
 */
function normPath( p ) {
        if ( ! p || typeof p !== 'string' ) return '';
        return p.replace( /\\+/g, '/' ).replace( /^\/+/, '' );
}

export default function ErrorCheckModal( {
        open,
        result,
        onClose,
        onOpenFile,    // ( node, line, col ) => void
} ) {
        const [ collapsed, setCollapsed ] = useState( new Set() );

        // Reset collapsed state every time a new result arrives.
        useEffect( () => {
                if ( open ) {
                        setCollapsed( new Set() );
                }
        }, [ open, result ] );

        // ESC closes (but NOT in blocking mode — the user should read the
        // errors, not accidentally dismiss them and think the save succeeded).
        useEffect( () => {
                if ( ! open ) return;
                const h = ( e ) => {
                        if ( e.key !== 'Escape' ) return;
                        // Always allow ESC to close — the file is unchanged on close
                        // anyway, so there's no risk of "accidentally saving".
                        onClose();
                };
                window.addEventListener( 'keydown', h );
                return () => window.removeEventListener( 'keydown', h );
        }, [ open, onClose ] );

        const rawErrors      = result?.errors || [];
        const savedFile      = normPath( result?.savedFile || result?.path || '' );
        const totalErrors    = result?.totalErrors || 0;
        const scannedFiles   = result?.scannedFiles || 0;
        const engine         = result?.engine || '';
        const truncated      = !! result?.truncated;
        const hasCritical    = !! result?.hasCritical;
        const hasWarning     = !! result?.hasWarning;
        const onSaveAnyway   = result?.onSaveAnyway;

        // Normalise every error's `file` field to forward slashes, and
        // recompute `isSavedFile` consistently using the normalised savedFile
        // path.
        const errors = useMemo( () => rawErrors.map( ( e ) => ( {
                ...e,
                file:        normPath( e.file ),
                isSavedFile: savedFile !== '' && normPath( e.file ) === savedFile,
        } ) ), [ rawErrors, savedFile ] );

        // Group errors by file.
        const grouped = useMemo( () => {
                const map = new Map();
                for ( const e of errors ) {
                        if ( ! map.has( e.file ) ) map.set( e.file, [] );
                        map.get( e.file ).push( e );
                }
                return [ ...map.entries() ].sort( ( a, b ) => {
                        if ( a[ 0 ] === savedFile ) return -1;
                        if ( b[ 0 ] === savedFile ) return 1;
                        return a[ 0 ].localeCompare( b[ 0 ] );
                } );
        }, [ errors, savedFile ] );

        // Counters.
        const criticalCount = errors.filter( ( e ) => e.severity === 'error' ).length;
        const warningCount  = errors.filter( ( e ) => e.severity === 'warning' ).length;

        // The mode determines the footer buttons.
        const isBlocking = hasCritical;

        if ( ! open || ! result ) return null;

        const toggleCollapse = ( file ) => {
                setCollapsed( ( prev ) => {
                        const next = new Set( prev );
                        if ( next.has( file ) ) next.delete( file );
                        else next.add( file );
                        return next;
                } );
        };

        const handleJump = ( err ) => {
                const path = normPath( err.file );
                const name = path.split( '/' ).pop() || path;
                onOpenFile?.(
                        { path, name, ext: 'php', type: 'file' },
                        err.line,
                        err.column
                );
                // Close the popup after jumping to the error line — the
                // user clicked "Open" to go fix the code, so they don't
                // need the popup covering the editor anymore.
                onClose();
        };

        const handleSaveAnyway = async () => {
                if ( ! onSaveAnyway ) {
                        onClose();
                        return;
                }
                try {
                        await onSaveAnyway();
                        onClose();
                } catch ( err ) {
                        // The save failed — keep the popup open so the user sees
                        // the error.
                }
        };

        return (
                <div className="wptd-modal-overlay wptd-errorcheck-overlay" onClick={ onClose }>
                        <div
                                className={ `wptd-errorcheck ${ isBlocking ? 'wptd-errorcheck--blocking' : 'wptd-errorcheck--warning' }` }
                                onClick={ ( e ) => e.stopPropagation() }
                                role="dialog"
                                aria-modal="true"
                                aria-label={ __( 'Code errors detected', 'wptd' ) }
                        >
                                {/* Header */}
                                <div className={ `wptd-errorcheck__header ${ isBlocking ? 'is-blocking' : 'is-warning' }` }>
                                        <div className="wptd-errorcheck__title-block">
                                                <span className={ `wptd-errorcheck__badge ${ isBlocking ? 'is-blocking' : 'is-warning' }` }>
                                                        <Icon name={ isBlocking ? 'alert-circle' : 'warning' } size={ 18 } />
                                                        { totalErrors }
                                                </span>
                                                <div>
                                                        <h2 className="wptd-errorcheck__title">
                                                                { isBlocking
                                                                        ? __( 'Critical errors — save blocked', 'wptd' )
                                                                        : __( 'Warnings detected', 'wptd' )
                                                                }
                                                        </h2>
                                                        <p className="wptd-errorcheck__subtitle">
                                                                { savedFile
                                                                        ? __( 'File:', 'wptd' ) + ' '
                                                                        : __( 'No file path provided.', 'wptd' ) }
                                                                { savedFile && <code>{ savedFile }</code> }
                                                        </p>
                                                </div>
                                        </div>
                                        <button
                                                className="wptd-modal__close"
                                                onClick={ onClose }
                                                aria-label={ __( 'Close', 'wptd' ) }
                                        >✕</button>
                                </div>

                                {/* Severity banner — explains the mode in plain language */}
                                <div className={ `wptd-errorcheck__banner ${ isBlocking ? 'is-blocking' : 'is-warning' }` }>
                                        <span className="wptd-errorcheck__banner-icon">
                                                <Icon name={ isBlocking ? 'alert-circle' : 'warning' } size={ 16 } />
                                        </span>
                                        <span className="wptd-errorcheck__banner-text">
                                                { isBlocking ? (
                                                        <>
                                                                <strong>{ criticalCount } { criticalCount === 1 ? __( 'critical error', 'wptd' ) : __( 'critical errors', 'wptd' ) }</strong>
                                                                { ' ' }— { __( 'saving this file would break your site. Fix the error(s) above, then click Save again.', 'wptd' ) }
                                                        </>
                                                ) : (
                                                        <>
                                                                <strong>{ warningCount } { warningCount === 1 ? __( 'warning', 'wptd' ) : __( 'warnings', 'wptd' ) }</strong>
                                                                { ' ' }— { __( 'these will not break your site. You can fix them now, or save anyway and fix later.', 'wptd' ) }
                                                        </>
                                                ) }
                                        </span>
                                </div>

                                {/* Summary */}
                                <div className="wptd-errorcheck__toolbar">
                                        <div className="wptd-errorcheck__summary">
                                                <span>
                                                        <strong>{ totalErrors }</strong> { __( 'issue(s)' ) }
                                                </span>
                                                { criticalCount > 0 && (
                                                        <>
                                                                <span className="wptd-errorcheck__dot">·</span>
                                                                <span className="wptd-errorcheck__crit-count">
                                                                        { criticalCount } { __( 'critical', 'wptd' ) }
                                                                </span>
                                                        </>
                                                ) }
                                                { warningCount > 0 && (
                                                        <>
                                                                <span className="wptd-errorcheck__dot">·</span>
                                                                <span className="wptd-errorcheck__warn-count">
                                                                        { warningCount } { __( 'warning(s)', 'wptd' ) }
                                                                </span>
                                                        </>
                                                ) }
                                                { engine && (
                                                        <>
                                                                <span className="wptd-errorcheck__dot">·</span>
                                                                <span className="wptd-errorcheck__engine">
                                                                        { engine === 'php-cli' ? 'php -l' : 'token_get_all' }
                                                                </span>
                                                        </>
                                                ) }
                                                { truncated && (
                                                        <>
                                                                <span className="wptd-errorcheck__dot">·</span>
                                                                <span className="wptd-errorcheck__truncated">
                                                                        { __( 'scan truncated', 'wptd' ) }
                                                                </span>
                                                        </>
                                                ) }
                                        </div>
                                </div>

                                {/* Body — grouped error list */}
                                <div className="wptd-errorcheck__body">
                                        { errors.length === 0 ? (
                                                <div className="wptd-empty wptd-empty--inline">
                                                        <div className="wptd-empty__icon"><Icon name="check-circle" size={ 48 } /></div>
                                                        <h3>{ __( 'No errors', 'wptd' ) }</h3>
                                                        <p>{ __( 'The lint check passed cleanly.', 'wptd' ) }</p>
                                                </div>
                                        ) : (
                                                grouped.map( ( [ file, list ] ) => {
                                                        const isSaved = file === savedFile;
                                                        const isCollapsed = collapsed.has( file );
                                                        return (
                                                                <div
                                                                        key={ file }
                                                                        className={ `wptd-errorcheck__group ${ isSaved ? 'is-saved' : '' }` }
                                                                >
                                                                        <div
                                                                                className="wptd-errorcheck__group-header"
                                                                                onClick={ () => toggleCollapse( file ) }
                                                                        >
                                                                                <span className="wptd-errorcheck__group-caret">
                                                                                        <Icon name={ isCollapsed ? 'chevron-right' : 'chevron-down' } size={ 14 } />
                                                                                </span>
                                                                                <span className="wptd-errorcheck__group-icon">
                                                                                        <Icon name={ isSaved ? 'file-text' : 'file' } size={ 14 } />
                                                                                </span>
                                                                                <span className="wptd-errorcheck__group-path" title={ file }>
                                                                                        { file }
                                                                                </span>
                                                                                { isSaved && (
                                                                                        <span className="wptd-pill wptd-pill--active">
                                                                                                <span className="wptd-pill__dot" />
                                                                                                { __( 'This file', 'wptd' ) }
                                                                                        </span>
                                                                                ) }
                                                                                <span className="wptd-errorcheck__group-count">
                                                                                        { list.length } { list.length === 1 ? __( 'issue', 'wptd' ) : __( 'issues', 'wptd' ) }
                                                                                </span>
                                                                        </div>

                                                                        { ! isCollapsed && (
                                                                                <ul className="wptd-errorcheck__errors">
                                                                                        { list.map( ( err, idx ) => (
                                                                                                <li key={ file + idx } className={ `wptd-errorcheck__error wptd-errorcheck__error--${ err.severity }` }>
                                                                                                        <div className="wptd-errorcheck__error-main">
                                                                                                                <div className="wptd-errorcheck__error-head">
                                                                                                                        <span className={ `wptd-errorcheck__line-badge wptd-errorcheck__line-badge--${ err.severity }` }>
                                                                                                                                { __( 'Line', 'wptd' ) } { err.line }
                                                                                                                                { err.column > 1 && <span>:{ err.column }</span> }
                                                                                                                        </span>
                                                                                                                        <span className="wptd-errorcheck__error-message">{ err.message }</span>
                                                                                                                        <button
                                                                                                                                className="wptd-errorcheck__jump"
                                                                                                                                onClick={ () => handleJump( err ) }
                                                                                                                                title={ __( 'Jump to this line', 'wptd' ) }
                                                                                                                        >
                                                                                                                                <Icon name="arrow-up" size={ 12 } />
                                                                                                                                { __( 'Open', 'wptd' ) }
                                                                                                                        </button>
                                                                                                                </div>
                                                                                                                <div className="wptd-errorcheck__solution">
                                                                                                                        <span className="wptd-errorcheck__solution-label">
                                                                                                                                <Icon name="info" size={ 12 } />
                                                                                                                                { __( 'Suggested fix', 'wptd' ) }
                                                                                                                        </span>
                                                                                                                        <p>{ err.solution }</p>
                                                                                                                </div>
                                                                                                        </div>
                                                                                                </li>
                                                                                        ) ) }
                                                                                </ul>
                                                                        ) }
                                                                </div>
                                                        );
                                                } )
                                        ) }
                                </div>

                                {/* Footer — context-dependent */}
                                <div className={ `wptd-errorcheck__footer ${ isBlocking ? 'is-blocking' : 'is-warning' }` }>
                                        <span className="wptd-errorcheck__hint">
                                                <Icon name={ isBlocking ? 'alert-circle' : 'info' } size={ 12 } />
                                                { isBlocking
                                                        ? __( 'The file was NOT saved. Fix the critical errors above, then click Save again.', 'wptd' )
                                                        : __( 'Warnings will not break your site. You can save now and fix them later.', 'wptd' )
                                                }
                                        </span>
                                        <div className="wptd-errorcheck__footer-actions">
                                                <button className="wptd-btn wptd-btn--ghost" onClick={ onClose }>
                                                        { isBlocking ? __( 'Close', 'wptd' ) : __( 'Cancel', 'wptd' ) }
                                                </button>
                                                { ! isBlocking && onSaveAnyway && (
                                                        <button
                                                                className="wptd-btn wptd-btn--warning"
                                                                onClick={ handleSaveAnyway }
                                                        >
                                                                <Icon name="save" size={ 14 } />
                                                                { __( 'Save Anyway', 'wptd' ) }
                                                        </button>
                                                ) }
                                        </div>
                                </div>
                        </div>
                </div>
        );
}
