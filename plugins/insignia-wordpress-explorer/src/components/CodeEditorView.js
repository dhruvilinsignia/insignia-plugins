/**
 * CodeEditorView.js — VS Code-like layout with whole-layout fullscreen.
 *
 * Layout (normal):
 *  - Sticky left sidebar (file explorer) — does NOT scroll with page
 *  - Main editor area (tabs + code) — scrolls independently inside the editor pane
 *  - Global search is a POPUP overlay (Cmd+Shift+F), not a sidebar column
 *
 * Fullscreen:
 *  - Either pane's fullscreen button promotes the WHOLE 2-pane layout
 *    (file tree + code editor together) to cover the viewport, so the
 *    code editor is always visible in fullscreen.
 *  - Esc or the exit bar restores the normal in-page layout.
 *
 * Supports three browsing modes:
 *  - "plugin":  browse a specific plugin
 *  - "theme":   browse a specific theme
 *  - "wordpress": browse the entire WordPress installation (ABSPATH)
 */
import { useState, useCallback, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import FileExplorer from './FileExplorer';
import EditorTabs from './EditorTabs';
import CodeEditor from './CodeEditor';
import GlobalSearch from './GlobalSearch';
import ErrorCheckModal from './ErrorCheckModal';
import { Icon } from './Icon';
import { toast } from './Toast';
import { confirmDialog } from './ConfirmDialog';

export default function CodeEditorView( { items, activeTab } ) {
        // Browsing mode: 'item' (plugin/theme) or 'wordpress'
        const [ browseMode, setBrowseMode ] = useState( 'item' );

        // Selected slug PER TAB: { plugins: 'akismet', themes: 'twentytwentyfive' }.
        // Storing the slug per tab (instead of a single editingSlug) means the
        // slug rendered for the current tab is ALWAYS valid for that tab —
        // there is never an intermediate render where type='theme' is paired
        // with a plugin slug. That mismatch was the bug that made the Themes
        // tab fire /file-tree?type=theme&slug=<plugin-slug> → 404
        // "Directory not found" and left the theme explorer/editor broken.
        const [ slugs, setSlugs ]             = useState( {} );
        const [ pickerOpen, setPickerOpen ]   = useState( false );

        // Open files & active file.
        const [ openFiles, setOpenFiles ]     = useState( [] );
        const [ activePath, setActivePath ]   = useState( null );
        const [ dirty, setDirty ]             = useState( new Set() );
        const [ refreshKey, setRefreshKey ]   = useState( 0 );
        const [ showSearch, setShowSearch ]   = useState( false );

        // Lint result popup — populated when a save triggers lint errors.
        const [ lintResult, setLintResult ]   = useState( null );

        // Fullscreen: promotes the WHOLE editor layout (file tree + code
        // editor together, VS Code style) to cover the viewport.
        const [ fullscreen, setFullscreen ]   = useState( false );

        // ── Reset open files synchronously when the user switches tabs ──────────
        // React render-phase state update: when activeTab changes, React discards
        // this render and immediately re-renders with the cleared state BEFORE
        // committing to the DOM. So no effect ever runs with a stale file from
        // the previous tab (which used to fire a doomed
        // /file?type=theme&path=<old-plugin-file> request).
        const [ prevTab, setPrevTab ] = useState( activeTab );
        if ( prevTab !== activeTab ) {
                setPrevTab( activeTab );
                setOpenFiles( [] );
                setActivePath( null );
                setDirty( new Set() );
                setPickerOpen( false );
        }

        // Derive the editing slug for the CURRENT tab during render:
        // use the remembered per-tab choice if it still exists in the item
        // list, otherwise fall back to the first item.
        const itemType   = activeTab === 'plugins' ? 'plugin' : 'theme';
        const stored     = slugs[ activeTab ];
        const editingSlug = ( stored && items?.some( ( i ) => i.slug === stored ) )
                ? stored
                : ( items?.[ 0 ]?.slug || null );

        const type = browseMode === 'wordpress' ? 'wordpress' : itemType;
        const slug = browseMode === 'wordpress' ? '' : editingSlug;

        const handleOpenFile = useCallback( ( node, line, col ) => {
                // Normalise the path so that jump-from-error (which may carry
                // a backslash-separated path on Windows servers) always
                // matches an already-open tab.
                const normPath = ( p ) => ( p || '' ).replace( /\\+/g, '/' ).replace( /^\/+/, '' );
                const targetPath = normPath( node.path );

                setOpenFiles( ( prev ) => {
                        const found = prev.find( ( f ) => normPath( f.path ) === targetPath );
                        if ( found ) return prev;
                        return [ ...prev, {
                                path: targetPath,
                                name: node.name || targetPath.split( '/' ).pop(),
                                ext:  node.ext || targetPath.split( '.' ).pop(),
                                type: 'file',
                        } ];
                } );
                setActivePath( targetPath );

                if ( line ) {
                        setTimeout( () => {
                                window.dispatchEvent( new CustomEvent( 'wptd:goto', {
                                        detail: { path: targetPath, line, col: col || 1 },
                                } ) );
                        }, 400 );
                }
        }, [] );

        const handleCloseFile = async ( path ) => {
                if ( dirty.has( path ) ) {
                        const ok = await confirmDialog( {
                                title: __( 'Close without saving?', 'wptd' ),
                                message: __( 'This file has unsaved changes that will be lost if you close it.', 'wptd' ),
                                confirmLabel: __( 'Close anyway', 'wptd' ),
                                cancelLabel: __( 'Keep open', 'wptd' ),
                                variant: 'warning',
                                icon: 'alert-circle',
                        } );
                        if ( ! ok ) return;
                }
                // Compute the new openFiles list from the CURRENT state (using
                // the functional updater so we never read a stale closure
                // value), and derive the next activePath from that new list.
                setOpenFiles( ( prev ) => {
                        const remaining = prev.filter( ( f ) => f.path !== path );
                        if ( activePath === path ) {
                                setActivePath(
                                        remaining.length ? remaining[ remaining.length - 1 ].path : null
                                );
                        }
                        return remaining;
                } );
                setDirty( ( prev ) => { const n = new Set( prev ); n.delete( path ); return n; } );
        };

        const handleDirty = ( path, isDirty ) => {
                setDirty( ( prev ) => {
                        const next = new Set( prev );
                        if ( isDirty ) next.add( path );
                        else next.delete( path );
                        return next;
                } );
        };

        const handleSaved = ( path ) => {
                setDirty( ( prev ) => { const n = new Set( prev ); n.delete( path ); return n; } );
        };

        const handleRefresh = () => setRefreshKey( ( k ) => k + 1 );

        const activeFile = openFiles.find( ( f ) => f.path === activePath );

        const switchTarget = async ( newSlug ) => {
                if ( dirty.size > 0 ) {
                        const ok = await confirmDialog( {
                                title: __( 'Switch plugin/theme?', 'wptd' ),
                                message: __(
                                        'You have unsaved changes in open tabs. Switching now will discard those changes.',
                                        'wptd'
                                ),
                                confirmLabel: __( 'Switch & discard', 'wptd' ),
                                cancelLabel: __( 'Stay here', 'wptd' ),
                                variant: 'warning',
                                icon: 'alert-circle',
                        } );
                        if ( ! ok ) return;
                }
                setSlugs( ( prev ) => ( { ...prev, [ activeTab ]: newSlug } ) );
                setOpenFiles( [] );
                setActivePath( null );
                setDirty( new Set() );
                setRefreshKey( ( k ) => k + 1 );
                setPickerOpen( false );
                toast( __( 'Editing', 'wptd' ) + ': ' + newSlug, 'info', 2000 );
        };

        const switchMode = async ( mode ) => {
                if ( dirty.size > 0 ) {
                        const ok = await confirmDialog( {
                                title: __( 'Switch browsing mode?', 'wptd' ),
                                message: __(
                                        'You have unsaved changes in open tabs. Switching mode will discard those changes.',
                                        'wptd'
                                ),
                                confirmLabel: __( 'Switch & discard', 'wptd' ),
                                cancelLabel: __( 'Stay here', 'wptd' ),
                                variant: 'warning',
                                icon: 'alert-circle',
                        } );
                        if ( ! ok ) return;
                }
                setBrowseMode( mode );
                setOpenFiles( [] );
                setActivePath( null );
                setDirty( new Set() );
                setRefreshKey( ( k ) => k + 1 );
                setShowSearch( false );
        };

        // Global keyboard shortcuts:
        //  - Ctrl/Cmd+Shift+F → global search
        //  - Esc → close search popup OR exit fullscreen pane
        useEffect( () => {
                const h = ( e ) => {
                        if ( ( e.metaKey || e.ctrlKey ) && e.shiftKey && e.key.toLowerCase() === 'f' ) {
                                e.preventDefault();
                                setShowSearch( true );
                                return;
                        }
                        if ( e.key === 'Escape' ) {
                                if ( showSearch ) {
                                        setShowSearch( false );
                                } else if ( fullscreen ) {
                                        setFullscreen( false );
                                }
                        }
                };
                window.addEventListener( 'keydown', h );
                return () => window.removeEventListener( 'keydown', h );
        }, [ showSearch, fullscreen ] );

        // Lock page scroll while a pane is fullscreen so the WP admin page
        // behind the fixed pane can't scroll underneath it.
        useEffect( () => {
                const cls = 'wptd-fs-lock';
                if ( fullscreen ) {
                        document.documentElement.classList.add( cls );
                        document.body.classList.add( cls );
                } else {
                        document.documentElement.classList.remove( cls );
                        document.body.classList.remove( cls );
                }
                return () => {
                        document.documentElement.classList.remove( cls );
                        document.body.classList.remove( cls );
                };
        }, [ fullscreen ] );

        const toggleFullscreen = () => setFullscreen( ( v ) => ! v );

        const editingLabel = browseMode === 'wordpress'
                ? 'WordPress Root'
                : ( editingSlug || __( 'Choose…', 'wptd' ) );

        return (
                <div
                        className={ `wptd-editor-view ${ fullscreen ? 'has-fullscreen' : '' }` }
                        data-fullscreen={ fullscreen ? 'layout' : '' }
                >
                        {/* Switcher bar */}
                        <div className="wptd-editor-switcher">
                                <div className="wptd-editor-switcher__info">
                                        <span className="wptd-editor-switcher__icon">
                                                { browseMode === 'wordpress'
                                                        ? <Icon name="server" size={ 18 } />
                                                        : <Icon name={ itemType === 'plugin' ? 'puzzle' : 'palette' } size={ 18 } />
                                                }
                                        </span>
                                        <div className="wptd-editor-switcher__text">
                                                <span className="wptd-editor-switcher__label">{ __( 'Editing', 'wptd' ) }</span>
                                                { browseMode === 'wordpress' ? (
                                                        <span className="wptd-editor-switcher__slug">
                                                                { __( 'WordPress Root', 'wptd' ) }
                                                        </span>
                                                ) : (
                                                        <button
                                                                className="wptd-editor-switcher__slug"
                                                                onClick={ () => setPickerOpen( ( v ) => ! v ) }
                                                        >
                                                                { editingLabel }
                                                                <Icon name="chevron-down" size={ 12 } />
                                                        </button>
                                                ) }
                                        </div>
                                </div>

                                <div className="wptd-editor-switcher__actions">
                                        <button
                                                className={ `wptd-icon-btn wptd-icon-btn--small ${ showSearch ? 'is-active' : '' }` }
                                                onClick={ () => setShowSearch( ( v ) => ! v ) }
                                                title={ __( 'Global search (Ctrl+Shift+F)', 'wptd' ) }
                                        >
                                                <Icon name="search" size={ 14 } />
                                        </button>
                                </div>

                                { pickerOpen && browseMode === 'item' && (
                                        <div className="wptd-editor-picker">
                                                <div className="wptd-editor-picker__list">
                                                        { items.map( ( item ) => (
                                                                <button
                                                                        key={ item.slug }
                                                                        className={ `wptd-editor-picker__item ${ item.slug === editingSlug ? 'is-active' : '' }` }
                                                                        onClick={ () => switchTarget( item.slug ) }
                                                                >
                                                                        <span className="wptd-editor-picker__icon">
                                                                                <Icon name={ itemType === 'plugin' ? 'puzzle' : 'palette' } size={ 16 } />
                                                                        </span>
                                                                        <span className="wptd-editor-picker__name">{ item.name }</span>
                                                                        <span className="wptd-editor-picker__slug">{ item.slug }</span>
                                                                        { item.active && <span className="wptd-pill wptd-pill--active"><span className="wptd-pill__dot" />{ __( 'Active', 'wptd' ) }</span> }
                                                                </button>
                                                        ) ) }
                                                </div>
                                        </div>
                                ) }
                        </div>

                        {/* Mode pills */}
                        <div className="wptd-editor-mode-pills">
                                <button
                                        className={ `wptd-editor-mode-pill ${ browseMode === 'item' ? 'is-active' : '' }` }
                                        onClick={ () => switchMode( 'item' ) }
                                >
                                        <Icon name={ itemType === 'plugin' ? 'puzzle' : 'palette' } size={ 14 } />
                                        { itemType === 'plugin' ? __( 'Plugin', 'wptd' ) : __( 'Theme', 'wptd' ) }
                                </button>
                                <button
                                        className={ `wptd-editor-mode-pill ${ browseMode === 'wordpress' ? 'is-active' : '' }` }
                                        onClick={ () => switchMode( 'wordpress' ) }
                                >
                                        <Icon name="server" size={ 14 } />
                                        { __( 'WordPress Root', 'wptd' ) }
                                </button>
                        </div>

                        {/* 2-pane layout: sidebar + editor.
                            When a pane goes fullscreen, CSS promotes it to
                            position:fixed (covering the viewport) so we keep
                            the SAME component instance — no duplicate state,
                            no duplicate CodeMirror, no race conditions. */}
                        <div className={ `wptd-editor-layout ${ fullscreen ? 'is-fullscreen' : '' }` }>
                                { ( browseMode === 'wordpress' || editingSlug ) ? (
                                        <>
                                                <div className="wptd-editor-sidebar">
                                                        <FileExplorer
                                                                type={ type }
                                                                slug={ slug }
                                                                activePath={ activePath }
                                                                onOpenFile={ handleOpenFile }
                                                                refreshKey={ refreshKey }
                                                                onRefresh={ handleRefresh }
                                                                isFullscreen={ fullscreen }
                                                                onToggleFullscreen={ toggleFullscreen }
                                                        />
                                                </div>

                                                <div className="wptd-editor-main">
                                                        <EditorTabs
                                                                openFiles={ openFiles }
                                                                activePath={ activePath }
                                                                onSelect={ setActivePath }
                                                                onClose={ handleCloseFile }
                                                                dirty={ dirty }
                                                        />
                                                        <CodeEditor
                                                                type={ type }
                                                                slug={ slug }
                                                                file={ activeFile }
                                                                onDirty={ handleDirty }
                                                                onSaved={ handleSaved }
                                                                onLintResult={ setLintResult }
                                                                isFullscreen={ fullscreen }
                                                                onToggleFullscreen={ toggleFullscreen }
                                                        />
                                                </div>
                                        </>
                                ) : (
                                        <div className="wptd-editor-empty">
                                                <div className="wptd-editor-empty__art">
                                                        <Icon name="folder" size={ 64 } />
                                                </div>
                                                <h3>{ __( 'Select a plugin or theme to edit', 'wptd' ) }</h3>
                                        </div>
                                ) }
                        </div>

                        {/* Fullscreen exit bar — rendered as a fixed overlay
                            on top of the promoted pane. The pane itself is
                            still the same single instance, just visually
                            promoted via CSS. */}
                        { fullscreen && (
                                <div className="wptd-fullscreen-bar">
                                        <span className="wptd-fullscreen-bar__title">
                                                <Icon name="code" size={ 14 } />
                                                { __( 'Code editor — Fullscreen', 'wptd' ) }
                                        </span>
                                        <button
                                                className="wptd-fullscreen-bar__exit"
                                                onClick={ () => setFullscreen( false ) }
                                                title={ __( 'Exit fullscreen (Esc)', 'wptd' ) }
                                        >
                                                <Icon name="minimize" size={ 14 } />
                                                { __( 'Exit fullscreen', 'wptd' ) }
                                                <kbd>Esc</kbd>
                                        </button>
                                </div>
                        ) }

                        {/* Global search popup overlay */}
                        { showSearch && (
                                <div className="wptd-search-popup-overlay" onClick={ () => setShowSearch( false ) }>
                                        <div className="wptd-search-popup" onClick={ ( e ) => e.stopPropagation() }>
                                                <GlobalSearch
                                                        type={ type }
                                                        slug={ slug }
                                                        onOpenFile={ ( node, line, col ) => {
                                                                handleOpenFile( node, line, col );
                                                                setShowSearch( false );
                                                        } }
                                                        onClose={ () => setShowSearch( false ) }
                                                />
                                        </div>
                                </div>
                        ) }

                        {/* Error-check popup — opens automatically when a save
                            produces PHP syntax errors in the saved file or in
                            other files of the same plugin / theme. */}
                        <ErrorCheckModal
                                open={ !! lintResult }
                                result={ lintResult }
                                onClose={ () => setLintResult( null ) }
                                onOpenFile={ ( node, line, col ) => {
                                        // Reuse the same open-file routine used by
                                        // global search so jumping to an error
                                        // works exactly like clicking a search hit.
                                        handleOpenFile( node, line, col );
                                } }
                        />
                </div>
        );
}
