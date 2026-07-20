/**
 * CodeEditor.js — CodeMirror-powered editor for editing plugin/theme files.
 *
 * Features
 *  - Mounts CodeMirror 5 (loaded via CDN in Assets.php)
 *  - Auto-detects mode from file extension
 *  - Cmd/Ctrl+S to save
 *  - Search overlay (Ctrl+F) using CodeMirror's built-in search addon
 *  - Code folding, line numbers, bracket matching, auto-close brackets/tags
 *  - Word wrap toggle
 *  - Live dirty indicator + save button
 *  - Status bar (path, language, size, line:col, modified)
 *  - Fullscreen toggle (button + Esc to exit) — the editor pane goes
 *    full-viewport without disturbing the rest of the page layout.
 */
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { readFile, writeFile, lintContent } from '../api/download';
import { extToMode, formatSize, timeAgo } from './utils';
import { Icon } from './Icon';
import { toast } from './Toast';

export default function CodeEditor( { type, slug, file, onDirty, onSaved, onLintResult, onForceSave, isFullscreen, onToggleFullscreen } ) {
        const containerRef = useRef( null );
        const cmRef = useRef( null );
        const cursorRef = useRef( null );

        const [ loading, setLoading ]   = useState( false );
        const [ error, setError ]       = useState( null );
        const [ saving, setSaving ]     = useState( false );
        const [ linting, setLinting ]   = useState( false );
        const [ dirty, setDirty ]       = useState( false );
        const [ meta, setMeta ]         = useState( null );
        const [ wrap, setWrap ]         = useState( false );
        const [ cursor, setCursor ]     = useState( { line: 1, col: 1 } );

        // Load file content when `file` changes.
        useEffect( () => {
                if ( ! file ) {
                        // File was closed / cleared (e.g. tab switch) — reset any
                        // stale error/meta so the empty state renders clean.
                        setError( null );
                        setMeta( null );
                        setDirty( false );
                        return;
                }
                let cancelled = false;
                setLoading( true );
                setError( null );
                setDirty( false );
                setMeta( null );

                readFile( type, slug, file.path )
                        .then( ( res ) => {
                                if ( cancelled ) return;
                                setMeta( res );
                                mountCM( res.content, extToMode( res.ext ) );
                                setLoading( false );
                        } )
                        .catch( ( err ) => {
                                if ( cancelled ) return;
                                setError( err.message || __( 'Failed to load file', 'wptd' ) );
                                setLoading( false );
                        } );

                return () => {
                        cancelled = true;
                        destroyCM();
                };
        }, [ type, slug, file?.path ] );

        const destroyCM = () => {
                // Disconnect the ResizeObserver FIRST so no queued callback
                // fires on a half-destroyed editor.
                if ( containerRef.current && containerRef.current._wptd_ro ) {
                        try { containerRef.current._wptd_ro.disconnect(); } catch ( _ ) {}
                        containerRef.current._wptd_ro = null;
                }
                if ( cmRef.current ) {
                        try {
                                // Wrap every cleanup call — CodeMirror can throw
                                // "Cannot read properties of undefined (reading 'prev')"
                                // if its internal line linked-list is in an
                                // inconsistent state after a rapid file switch.
                                const wrapper = cmRef.current.getWrapperElement();
                                if ( wrapper && wrapper.parentNode ) {
                                        wrapper.parentNode.removeChild( wrapper );
                                }
                        } catch ( _ ) {
                                // Swallow — the editor is being discarded anyway.
                        }
                        cmRef.current = null;
                }
        };

        /**
         * Safely call a CodeMirror method.  CodeMirror 5's internal line
         * model uses a linked list (each line has .prev / .next).  If the
         * document is in an inconsistent state — e.g. a queued
         * ResizeObserver callback fires after the editor's DOM was removed
         * — calling .refresh() can throw
         * "Cannot read properties of undefined (reading 'prev')".
         * This wrapper swallows that error so a dying editor never crashes
         * the React tree.
         */
        const safeCM = ( fn ) => {
                try { fn(); } catch ( _ ) { /* editor is tearing down — ignore */ }
        };

        const mountCM = ( content, mode ) => {
                destroyCM();
                if ( ! window.CodeMirror || ! containerRef.current ) return;

                const cm = window.CodeMirror( containerRef.current, {
                        value: content || '',
                        mode,
                        theme: 'material-darker',
                        lineNumbers: true,
                        lineWrapping: wrap,
                        indentUnit: 4,
                        tabSize: 4,
                        indentWithTabs: true,
                        matchBrackets: true,
                        autoCloseBrackets: true,
                        autoCloseTags: true,
                        foldGutter: true,
                        gutters: [ 'CodeMirror-linenumbers', 'CodeMirror-foldgutter' ],
                        extraKeys: {
                                'Cmd-S': () => save(),
                                'Ctrl-S': () => save(),
                                'Cmd-F': 'findPersistent',
                                'Ctrl-F': 'findPersistent',
                                'Cmd-G': 'findNext',
                                'Ctrl-G': 'findNext',
                                'Shift-Cmd-G': 'findPrev',
                                'Shift-Ctrl-G': 'findPrev',
                                // CodeMirror-native fullscreen (esc to exit) — works great with our layout.
                                'Cmd-E': () => onToggleFullscreen?.(),
                                'Ctrl-E': () => onToggleFullscreen?.(),
                                'Tab': ( cm ) => {
                                        if ( cm.somethingSelected() ) cm.indentSelection( 'add' );
                                        else cm.replaceSelection( '\t', 'end' );
                                },
                        },
                } );

                cmRef.current = cm;

                cm.on( 'change', ( instance, change ) => {
                        if ( change.origin === 'setValue' ) return; // ignore initial load
                        setDirty( true );
                        onDirty?.( file.path, true );
                } );

                cm.on( 'cursorActivity', () => {
                        const c = cm.getCursor();
                        setCursor( { line: c.line + 1, col: c.ch + 1 } );
                } );

                // Focus + refresh after mount, and observe container resize so the
                // editor always fills its parent correctly.
                setTimeout( () => safeCM( () => cm.refresh() ), 50 );

                if ( containerRef.current && window.ResizeObserver ) {
                        const ro = new ResizeObserver( () => {
                                // Guard against the editor being destroyed between
                                // the RO event being queued and the callback firing.
                                if ( ! cmRef.current ) return;
                                if ( ! containerRef.current ) return;
                                if ( ! containerRef.current.isConnected ) return;
                                safeCM( () => cm.refresh() );
                        } );
                        ro.observe( containerRef.current );
                        containerRef.current._wptd_ro = ro;
                }
        };

        // Toggle word wrap on demand.
        useEffect( () => {
                if ( cmRef.current ) {
                        safeCM( () => cmRef.current.setOption( 'lineWrapping', wrap ) );
                }
        }, [ wrap ] );

        // Refresh CodeMirror when entering/leaving fullscreen so it picks up the
        // new container dimensions.
        useEffect( () => {
                const t = setTimeout( () => {
                        if ( cmRef.current && containerRef.current?.isConnected ) {
                                safeCM( () => cmRef.current.refresh() );
                        }
                }, 60 );
                return () => clearTimeout( t );
        }, [ isFullscreen ] );

        /**
         * Actually write the file to disk. This is the "real" save — it
         * assumes the lint gate (if applicable) has already passed.
         * Extracted so the pre-lint flow can call it after the user
         * clicks "Save Anyway" on a warnings-only result.
         */
        const doWriteFile = useCallback( async ( content ) => {
                const res = await writeFile( type, slug, file.path, content );
                setDirty( false );
                onDirty?.( file.path, false );
                setMeta( ( m ) => ( { ...m, modified: res.modified, size: res.size } ) );
                onSaved?.( file.path );
                toast( __( 'Saved', 'wptd' ) + ': ' + file.name, 'success' );
                return res;
        }, [ type, slug, file, onDirty, onSaved ] );

        const save = useCallback( async ( { force = false } = {} ) => {
                if ( ! cmRef.current || ! file || saving ) return;
                setSaving( true );
                const content = cmRef.current.getValue();

                try {
                        // ── Pre-save lint gate (PHP files only) ────────────────────
                        //
                        // Before writing the file to disk, we lint the NEW
                        // content via /lint-content. The backend writes the
                        // content to a temp file and lints it — the real file
                        // on disk is NOT touched. This is the "safe coding"
                        // gate: critical errors never reach the live site.
                        //
                        // Outcomes:
                        //   - No errors              → save immediately.
                        //   - Only warnings          → show popup with
                        //                              "Save Anyway" + "Cancel".
                        //   - Any critical (error)   → show popup in BLOCKING
                        //                              mode — no save button,
                        //                              user MUST fix first.
                        //
                        // The `force` flag (set when the user clicks "Save
                        // Anyway" in the warnings popup) skips the gate.
                        const isPhp = ( file.ext || '' ).toLowerCase() === 'php'
                                || ( file.path || '' ).toLowerCase().endsWith( '.php' );

                        if ( isPhp && ! force ) {
                                setLinting( true );
                                let lintResult;
                                try {
                                        lintResult = await lintContent( type, slug, file.path, content );
                                } catch ( lintErr ) {
                                        // Lint endpoint itself failed (e.g. REST
                                        // error). Don't block the save — the
                                        // user might be on a host where the
                                        // linter can't run. Surface a warning
                                        // toast and proceed.
                                        setLinting( false );
                                        toast(
                                                __( 'Lint check unavailable, saving anyway: ', 'wptd' ) + ( lintErr?.message || '' ),
                                                'warn',
                                                5000
                                        );
                                        await doWriteFile( content );
                                        return;
                                }
                                setLinting( false );

                                if ( lintResult.totalErrors > 0 ) {
                                        // Pass the content + a "force save"
                                        // callback to the popup. The popup
                                        // decides whether to show "Save
                                        // Anyway" (warnings only) or to block
                                        // (critical errors).
                                        onLintResult?.( {
                                                ...lintResult,
                                                pendingContent: content,
                                                onSaveAnyway: async () => {
                                                        setSaving( true );
                                                        try {
                                                                await doWriteFile( content );
                                                        } catch ( err ) {
                                                                toast( err.message, 'error' );
                                                        } finally {
                                                                setSaving( false );
                                                        }
                                                },
                                        } );
                                        // Don't proceed to save here — the
                                        // popup's "Save Anyway" button (if
                                        // shown) will trigger it.
                                        return;
                                }

                                // No errors in this file — save!
                                // (The cross-file deep scan is available
                                // as a manual action from the Recent
                                // Changes panel — it is NOT run
                                // automatically on every save because the
                                // user asked to only see issues for the
                                // file they're currently editing.)
                                toast( __( 'No syntax errors — saving…', 'wptd' ), 'success', 2000 );
                        }

                        // ── Actually write the file ────────────────────────────────
                        await doWriteFile( content );
                } catch ( err ) {
                        toast( err.message, 'error' );
                } finally {
                        setSaving( false );
                }
        }, [ type, slug, file, saving, onDirty, onSaved, onLintResult, doWriteFile ] );

        // Global Ctrl+S hook (in case focus is outside the editor).
        useEffect( () => {
                const h = ( e ) => {
                        if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 's' ) {
                                e.preventDefault();
                                save();
                        }
                };
                window.addEventListener( 'keydown', h );
                return () => window.removeEventListener( 'keydown', h );
        }, [ save ] );

        // Jump-to-line when a search hit is clicked (CustomEvent dispatched by CodeEditorView).
        // Also used by the lint ErrorCheckModal to jump to an error line.
        // Both paths are normalised to forward slashes before comparison so
        // the jump works regardless of the OS the WordPress server runs on.
        //
        // If CodeMirror isn't mounted yet (e.g. the file is still loading
        // after the user clicked "Open at line N" in the error popup), the
        // handler retries a few times with a short delay so the jump still
        // lands once the editor is ready.
        useEffect( () => {
                const tryJump = ( detail, attempt = 0 ) => {
                        if ( ! file ) return;
                        const norm = ( p ) => ( p || '' ).replace( /\\+/g, '/' ).replace( /^\/+/, '' );
                        if ( norm( detail?.path ) !== norm( file.path ) ) return;
                        const cm = cmRef.current;
                        if ( ! cm ) {
                                // Editor not ready yet — retry up to 10 times
                                // with 150ms between attempts (covers ~1.5s
                                // of loading time, which is plenty for any
                                // reasonable file size).
                                if ( attempt < 10 ) {
                                        setTimeout( () => tryJump( detail, attempt + 1 ), 150 );
                                }
                                return;
                        }
                        const { line, col } = detail;
                        const tLine = Math.max( 0, line - 1 );
                        const tCh = Math.max( 0, ( col || 1 ) - 1 );
                        cm.setCursor( { line: tLine, ch: tCh } );
                        cm.focus();
                        cm.scrollIntoView( { line: tLine, ch: tCh }, 200 );
                };
                const h = ( e ) => tryJump( e.detail );
                window.addEventListener( 'wptd:goto', h );
                return () => window.removeEventListener( 'wptd:goto', h );
        }, [ file ] );

        if ( ! file ) {
                return (
                        <div className="wptd-editor-empty">
                                <div className="wptd-editor-empty__art">
                                        <Icon name="file-text" size={ 64 } strokeWidth={ 1.2 } />
                                </div>
                                <h3>{ __( 'Code editor', 'wptd' ) }</h3>
                                <p>{ __( 'Pick a plugin or theme above, then choose a file from the explorer to start editing.', 'wptd' ) }</p>
                                <div className="wptd-editor-empty__hints">
                                        <div className="wptd-hint"><kbd>Ctrl/⌘ + S</kbd><span>{ __( 'Save file', 'wptd' ) }</span></div>
                                        <div className="wptd-hint"><kbd>Ctrl/⌘ + F</kbd><span>{ __( 'Search in file', 'wptd' ) }</span></div>
                                        <div className="wptd-hint"><kbd>Ctrl/⌘ + G</kbd><span>{ __( 'Next match', 'wptd' ) }</span></div>
                                </div>
                        </div>
                );
        }

        return (
                <div className={ `wptd-editor ${ isFullscreen ? 'is-fullscreen' : '' }` }>
                        <div className="wptd-editor__main">
                                { loading && (
                                        <div className="wptd-editor__loading">
                                                { [ 80, 95, 70, 60, 90, 50, 75 ].map( ( w, i ) => (
                                                        <div key={ i } className="wptd-skeleton-line" style={ { width: w + '%' } } />
                                                ) ) }
                                        </div>
                                ) }

                                { error && (
                                        <div className="wptd-editor__error">
                                                <Icon name="alert-circle" size={ 40 } />
                                                <h4>{ __( 'Cannot open file', 'wptd' ) }</h4>
                                                <p>{ error }</p>
                                        </div>
                                ) }

                                <div
                                        className="wptd-editor__cm"
                                        ref={ containerRef }
                                        style={ { display: loading || error ? 'none' : 'block' } }
                                />
                        </div>

                        { meta && (
                                <div className="wptd-editor__statusbar">
                                        <div className="wptd-statusbar__left">
                                                <span className="wptd-statusbar__item" title={ file.path }>
                                                        <Icon name="file-text" size={ 12 } /> { file.name }
                                                </span>
                                                <span className="wptd-statusbar__dot">·</span>
                                                <span className="wptd-statusbar__item">{ meta.ext || 'txt' }</span>
                                                <span className="wptd-statusbar__dot">·</span>
                                                <span className="wptd-statusbar__item">{ formatSize( meta.size || 0 ) }</span>
                                                { meta.modified > 0 && (
                                                        <>
                                                                <span className="wptd-statusbar__dot">·</span>
                                                                <span className="wptd-statusbar__item" title={ new Date( meta.modified * 1000 ).toLocaleString() }>
                                                                        { __( 'modified', 'wptd' ) } { timeAgo( meta.modified ) }
                                                                </span>
                                                        </>
                                                ) }
                                                { dirty && (
                                                        <>
                                                                <span className="wptd-statusbar__dot">·</span>
                                                                <span className="wptd-statusbar__item wptd-statusbar__item--warn">● { __( 'Unsaved', 'wptd' ) }</span>
                                                        </>
                                                ) }
                                                { linting && (
                                                        <>
                                                                <span className="wptd-statusbar__dot">·</span>
                                                                <span className="wptd-statusbar__item wptd-statusbar__item--info">
                                                                        <span className="wptd-btn-spinner wptd-btn-spinner--xs" />
                                                                        { __( 'Checking syntax…', 'wptd' ) }
                                                                </span>
                                                        </>
                                                ) }
                                        </div>

                                        <div className="wptd-statusbar__right">
                                                <span className="wptd-statusbar__item">Ln { cursor.line }, Col { cursor.col }</span>
                                                <button
                                                        className={ `wptd-statusbar__btn ${ wrap ? 'is-active' : '' }` }
                                                        onClick={ () => setWrap( ( w ) => ! w ) }
                                                        title={ __( 'Toggle word wrap', 'wptd' ) }
                                                >
                                                        <Icon name="wrap" size={ 12 } /> { __( 'Wrap', 'wptd' ) }
                                                </button>
                                                <button
                                                        className="wptd-statusbar__btn"
                                                        onClick={ () => cmRef.current?.execCommand( 'findPersistent' ) }
                                                        title={ __( 'Find in file', 'wptd' ) }
                                                >
                                                        <Icon name="find" size={ 12 } /> { __( 'Find', 'wptd' ) }
                                                </button>
                                                <button
                                                        className="wptd-statusbar__btn wptd-statusbar__btn--fs"
                                                        title={ isFullscreen ? __( 'Exit fullscreen (Esc)', 'wptd' ) : __( 'Fullscreen', 'wptd' ) }
                                                        onClick={ onToggleFullscreen }
                                                >
                                                        <Icon name={ isFullscreen ? 'minimize' : 'maximize' } size={ 12 } />
                                                        { isFullscreen ? __( 'Exit', 'wptd' ) : __( 'Full', 'wptd' ) }
                                                </button>
                                                <button
                                                        className={ `wptd-statusbar__btn wptd-statusbar__btn--primary ${ saving ? 'is-busy' : '' } ${ dirty ? 'is-dirty' : '' }` }
                                                        onClick={ save }
                                                        disabled={ saving || linting || ! dirty }
                                                >
                                                        { saving ? (
                                                                <span className="wptd-btn-spinner wptd-btn-spinner--xs" />
                                                        ) : linting ? (
                                                                <span className="wptd-btn-spinner wptd-btn-spinner--xs" />
                                                        ) : (
                                                                <Icon name="save" size={ 12 } strokeWidth={ 2.4 } />
                                                        ) }
                                                        { saving ? __( 'Saving…', 'wptd' )
                                                                : linting ? __( 'Checking…', 'wptd' )
                                                                : dirty ? __( 'Save', 'wptd' )
                                                                : __( 'Saved', 'wptd' ) }
                                                </button>
                                        </div>
                                </div>
                        ) }
                </div>
        );
}
