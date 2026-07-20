/**
 * FileExplorer.js — left sidebar file tree for the code editor.
 *
 * Supports two modes:
 *  - "item" mode: browse a specific plugin or theme (type=plugin|theme, slug=xxx)
 *  - "wordpress" mode: browse the entire WordPress installation (ABSPATH)
 *
 * Features: lazy tree, right-click context menu, new/rename/delete, SVG icons,
 *           and a fullscreen toggle (Esc to exit).
 */
import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { fetchFileTree, deleteFile, createFile, renameFile, triggerFileDownload } from '../api/download';
import { Icon, FileIcon, FolderIcon } from './Icon';
import { toast } from './Toast';
import { confirmDialog } from './ConfirmDialog';

function TreeNode( {
        node, depth, activePath, expanded, onToggle, onOpenFile,
        onContext, indent = 14,
} ) {
        const pad = { paddingLeft: `${ depth * indent + 10 }px` };

        if ( node.type === 'more' ) {
                return (
                        <div className="wptd-tree-row wptd-tree-row--more" style={ pad }>
                                <span className="wptd-tree-row__caret" />
                                <span className="wptd-tree-row__icon"><Icon name="file" size={ 14 } /></span>
                                <span className="wptd-tree-row__name">{ node.name }</span>
                        </div>
                );
        }

        const isDir = node.type === 'dir';
        const isActive = ! isDir && activePath === node.path;
        const isOpen = expanded.has( node.path );

        return (
                <>
                        <div
                                className={ `wptd-tree-row ${ isDir ? 'is-dir' : 'is-file' } ${ isActive ? 'is-active' : '' } ${ isOpen ? 'is-open' : '' }` }
                                style={ pad }
                                onClick={ () => ! isDir && onOpenFile( node ) }
                                onContextMenu={ ( e ) => { e.preventDefault(); onContext( e, node ); } }
                                title={ node.path }
                        >
                                <span
                                        className="wptd-tree-row__caret"
                                        onClick={ ( e ) => {
                                                if ( isDir ) {
                                                        e.stopPropagation();
                                                        onToggle( node.path );
                                                }
                                        } }
                                        style={ { cursor: isDir ? 'pointer' : 'default' } }
                                >
                                        { isDir ? <Icon name={ isOpen ? 'chevron-down' : 'chevron-right' } size={ 12 } /> : null }
                                </span>
                                <span className="wptd-tree-row__icon">
                                        { isDir ? <FolderIcon open={ isOpen } size={ 16 } /> : <FileIcon ext={ node.ext } size={ 16 } /> }
                                </span>
                                <span className="wptd-tree-row__name">{ node.name }</span>
                        </div>

                        { isDir && isOpen && node.children && node.children.map( ( child ) => (
                                <TreeNode
                                        key={ child.path || child.name }
                                        node={ child }
                                        depth={ depth + 1 }
                                        activePath={ activePath }
                                        expanded={ expanded }
                                        onToggle={ onToggle }
                                        onOpenFile={ onOpenFile }
                                        onContext={ onContext }
                                        indent={ indent }
                                />
                        ) ) }
                </>
        );
}

export default function FileExplorer( {
        type, slug,            // type = 'plugin' | 'theme' | 'wordpress'
        activePath,
        onOpenFile,
        refreshKey,
        onRefresh,
        isFullscreen,
        onToggleFullscreen,
} ) {
        const [ tree, setTree ]         = useState( null );
        const [ error, setError ]       = useState( null );
        const [ loading, setLoading ]   = useState( false );
        const [ expanded, setExpanded ] = useState( new Set() );
        const [ ctxMenu, setCtxMenu ]   = useState( null );
        const [ ctxNode, setCtxNode ]   = useState( null );
        const [ dialog, setDialog ]     = useState( null );
        const ctxRef = useRef( null );

        const rootName = type === 'wordpress' ? 'WordPress Root' : slug;

        const load = useCallback( async () => {
                if ( ! type || ( type !== 'wordpress' && ! slug ) ) return;
                setLoading( true );
                setError( null );
                try {
                        const res = await fetchFileTree( type, slug || '' );
                        setTree( res.children || [] );
                        setExpanded( new Set() );
                } catch ( err ) {
                        setError( err.message || __( 'Failed to load file tree', 'wptd' ) );
                } finally {
                        setLoading( false );
                }
        }, [ type, slug ] );

        useEffect( () => { load(); }, [ type, slug, refreshKey ] );

        useEffect( () => {
                const h = () => setCtxMenu( null );
                if ( ctxMenu ) {
                        window.addEventListener( 'click', h );
                        window.addEventListener( 'scroll', h, true );
                }
                return () => {
                        window.removeEventListener( 'click', h );
                        window.removeEventListener( 'scroll', h, true );
                };
        }, [ ctxMenu ] );

        const toggle = ( path ) => {
                setExpanded( ( prev ) => {
                        const next = new Set( prev );
                        if ( next.has( path ) ) next.delete( path );
                        else next.add( path );
                        return next;
                } );
        };

        const onContext = ( e, node ) => {
                setCtxNode( node );
                setCtxMenu( { x: e.clientX, y: e.clientY } );
        };

        const expandAll = () => {
                const next = new Set();
                const walk = ( nodes ) => {
                        nodes.forEach( ( n ) => {
                                if ( n.type === 'dir' ) { next.add( n.path ); if ( n.children ) walk( n.children ); }
                        } );
                };
                walk( tree || [] );
                setExpanded( next );
        };

        const collapseAll = () => setExpanded( new Set() );

        const startNewFile  = ( parentPath = '' ) => setDialog( { mode: 'new-file', node: { path: parentPath }, value: '' } );
        const startNewFolder = ( parentPath = '' ) => setDialog( { mode: 'new-folder', node: { path: parentPath }, value: '' } );
        const startRename   = () => setDialog( { mode: 'rename', node: ctxNode, value: ctxNode?.name || '' } );

        const startDownload = ( node ) => {
                if ( ! node ) return;
                const isDir   = node.type === 'dir';
                // Folder → "<name>.zip"  |  File → real file name.
                const fname   = isDir ? ( node.name + '.zip' ) : node.name;
                try {
                        triggerFileDownload( type, slug || '', node.path, fname );
                        toast(
                                isDir
                                        ? __( 'Downloading folder as ZIP', 'wptd' ) + ': ' + node.name
                                        : __( 'Downloading file', 'wptd' ) + ': ' + node.name,
                                'success'
                        );
                } catch ( err ) {
                        toast( err?.message || __( 'Download failed', 'wptd' ), 'error' );
                }
        };

        const startDelete = async () => {
                if ( ! ctxNode ) return;
                const isDir = ctxNode.type === 'dir';
                const ok = await confirmDialog( {
                        title: isDir
                                ? __( 'Delete this folder?', 'wptd' )
                                : __( 'Delete this file?', 'wptd' ),
                        message: isDir
                                ? __( 'Deleting this folder will remove it and ALL of its contents. This cannot be undone.', 'wptd' )
                                : (
                                        <>
                                                <p>{ __( 'This file will be permanently deleted. This cannot be undone.', 'wptd' ) }</p>
                                                <code className="wptd-confirm__code">{ ctxNode.path }</code>
                                        </>
                                ),
                        confirmLabel: __( 'Delete', 'wptd' ),
                        cancelLabel: __( 'Cancel', 'wptd' ),
                        variant: 'danger',
                        icon: 'trash',
                } );
                if ( ! ok ) return;
                try {
                        await deleteFile( type, slug || '', ctxNode.path );
                        toast( __( 'Deleted', 'wptd' ) + ': ' + ctxNode.name, 'success' );
                        onRefresh();
                } catch ( err ) {
                        toast( err.message, 'error' );
                }
        };

        const submitDialog = async () => {
                if ( ! dialog ) return;
                const name = ( dialog.value || '' ).trim();
                if ( ! name ) { setDialog( null ); return; }

                try {
                        if ( dialog.mode === 'new-file' ) {
                                const parent = dialog.node?.path || '';
                                const path = parent ? parent + '/' + name : name;
                                await createFile( type, slug || '', path, false );
                                toast( __( 'File created', 'wptd' ) + ': ' + name, 'success' );
                                if ( parent ) setExpanded( ( p ) => new Set( p ).add( parent ) );
                                onRefresh();
                                setTimeout( () => onOpenFile( { path, name, ext: name.split( '.' ).pop(), type: 'file' } ), 200 );
                        } else if ( dialog.mode === 'new-folder' ) {
                                const parent = dialog.node?.path || '';
                                const path = parent ? parent + '/' + name : name;
                                await createFile( type, slug || '', path, true );
                                toast( __( 'Folder created', 'wptd' ) + ': ' + name, 'success' );
                                if ( parent ) setExpanded( ( p ) => new Set( p ).add( parent ) );
                                onRefresh();
                        } else if ( dialog.mode === 'rename' ) {
                                await renameFile( type, slug || '', dialog.node.path, name );
                                toast( __( 'Renamed to', 'wptd' ) + ' ' + name, 'success' );
                                onRefresh();
                        }
                } catch ( err ) {
                        toast( err.message, 'error' );
                } finally {
                        setDialog( null );
                }
        };

        return (
                <div className={ `wptd-explorer ${ isFullscreen ? 'is-fullscreen' : '' }` }>
                        <div className="wptd-explorer__head">
                                <div className="wptd-explorer__title">
                                        <span className="wptd-explorer__icon">
                                                { type === 'wordpress' ? <Icon name="server" size={ 16 } /> : <Icon name="folder" size={ 16 } /> }
                                        </span>
                                        <span className="wptd-explorer__slug" title={ rootName }>{ rootName }</span>
                                </div>
                                <div className="wptd-explorer__actions">
                                        <button className="wptd-explorer__btn" title={ __( 'New file', 'wptd' ) } onClick={ () => startNewFile( '' ) }>
                                                <Icon name="file-plus" size={ 14 } />
                                        </button>
                                        <button className="wptd-explorer__btn" title={ __( 'New folder', 'wptd' ) } onClick={ () => startNewFolder( '' ) }>
                                                <Icon name="folder-plus" size={ 14 } />
                                        </button>
                                        <button className="wptd-explorer__btn" title={ __( 'Expand all', 'wptd' ) } onClick={ expandAll }>
                                                <Icon name="expand" size={ 14 } />
                                        </button>
                                        <button className="wptd-explorer__btn" title={ __( 'Collapse all', 'wptd' ) } onClick={ collapseAll }>
                                                <Icon name="collapse" size={ 14 } />
                                        </button>
                                        <button className="wptd-explorer__btn" title={ __( 'Refresh', 'wptd' ) } onClick={ load }>
                                                <Icon name="refresh" size={ 14 } />
                                        </button>
                                        <button
                                                className="wptd-explorer__btn wptd-explorer__btn--fs"
                                                title={ isFullscreen ? __( 'Exit fullscreen (Esc)', 'wptd' ) : __( 'Fullscreen', 'wptd' ) }
                                                onClick={ onToggleFullscreen }
                                        >
                                                <Icon name={ isFullscreen ? 'minimize' : 'maximize' } size={ 14 } />
                                        </button>
                                </div>
                        </div>

                        <div className="wptd-explorer__body">
                                { loading && (
                                        <div className="wptd-explorer__loading">
                                                { [ 0, 1, 2, 3, 4 ].map( ( i ) => (
                                                        <div key={ i } className="wptd-skeleton-line" style={ { width: `${ 60 + Math.random() * 35 }%`, marginLeft: `${ 10 + i * 8 }px` } } />
                                                ) ) }
                                        </div>
                                ) }

                                { error && (
                                        <div className="wptd-explorer__error">
                                                <Icon name="alert-circle" size={ 28 } />
                                                <p>{ error }</p>
                                                <button className="wptd-link-btn" onClick={ load }>
                                                        <Icon name="refresh" size={ 14 } /> { __( 'Retry', 'wptd' ) }
                                                </button>
                                        </div>
                                ) }

                                { ! loading && ! error && tree && tree.length === 0 && (
                                        <div className="wptd-explorer__empty">
                                                <Icon name="folder" size={ 28 } />
                                                <p>{ __( 'No editable files found.', 'wptd' ) }</p>
                                        </div>
                                ) }

                                { ! loading && ! error && tree && tree.length > 0 && (
                                        <div className="wptd-tree-list">
                                                { tree.map( ( node ) => (
                                                        <TreeNode
                                                                key={ node.path || node.name }
                                                                node={ node }
                                                                depth={ 0 }
                                                                activePath={ activePath }
                                                                expanded={ expanded }
                                                                onToggle={ toggle }
                                                                onOpenFile={ onOpenFile }
                                                                onContext={ onContext }
                                                        />
                                                ) ) }
                                        </div>
                                ) }
                        </div>

                        <div className="wptd-explorer__footer">
                                <span>{ type === 'wordpress' ? 'WordPress' : ( type === 'plugin' ? 'Plugin' : 'Theme' ) }</span>
                                <span className="wptd-explorer__dot">·</span>
                                <span>{ tree?.length || 0 } { __( 'items at root', 'wptd' ) }</span>
                        </div>

                        { ctxMenu && (
                                <div
                                        className="wptd-ctx-menu"
                                        ref={ ctxRef }
                                        style={ { top: ctxMenu.y, left: ctxMenu.x } }
                                        onClick={ ( e ) => e.stopPropagation() }
                                >
                                        { ctxNode?.type === 'dir' && (
                                                <>
                                                        <button className="wptd-ctx-item" onClick={ () => { startNewFile( ctxNode.path ); setCtxMenu( null ); } }>
                                                                <Icon name="file-plus" size={ 14 } /> { __( 'New file', 'wptd' ) }
                                                        </button>
                                                        <button className="wptd-ctx-item" onClick={ () => { startNewFolder( ctxNode.path ); setCtxMenu( null ); } }>
                                                                <Icon name="folder-plus" size={ 14 } /> { __( 'New folder', 'wptd' ) }
                                                        </button>
                                                        <div className="wptd-ctx-sep" />
                                                        <button className="wptd-ctx-item" onClick={ () => { startDownload( ctxNode ); setCtxMenu( null ); } }>
                                                                <Icon name="download" size={ 14 } /> { __( 'Download as ZIP', 'wptd' ) }
                                                        </button>
                                                        <div className="wptd-ctx-sep" />
                                                </>
                                        ) }
                                        { ctxNode?.type === 'file' && (
                                                <>
                                                        <button className="wptd-ctx-item" onClick={ () => { onOpenFile( ctxNode ); setCtxMenu( null ); } }>
                                                                <Icon name="file-text" size={ 14 } /> { __( 'Open', 'wptd' ) }
                                                        </button>
                                                        <button className="wptd-ctx-item" onClick={ () => { startDownload( ctxNode ); setCtxMenu( null ); } }>
                                                                <Icon name="download" size={ 14 } /> { __( 'Download', 'wptd' ) }
                                                        </button>
                                                        <div className="wptd-ctx-sep" />
                                                </>
                                        ) }
                                        <button className="wptd-ctx-item" onClick={ () => { startRename(); setCtxMenu( null ); } }>
                                                <Icon name="edit" size={ 14 } /> { __( 'Rename', 'wptd' ) }
                                        </button>
                                        <button className="wptd-ctx-item wptd-ctx-item--danger" onClick={ () => { startDelete(); setCtxMenu( null ); } }>
                                                <Icon name="trash" size={ 14 } /> { __( 'Delete', 'wptd' ) }
                                        </button>
                                </div>
                        ) }

                        { dialog && (
                                <div className="wptd-prompt-overlay" onClick={ () => setDialog( null ) }>
                                        <div className="wptd-prompt" onClick={ ( e ) => e.stopPropagation() }>
                                                <h3>
                                                        { dialog.mode === 'new-file' && __( 'Create new file', 'wptd' ) }
                                                        { dialog.mode === 'new-folder' && __( 'Create new folder', 'wptd' ) }
                                                        { dialog.mode === 'rename' && __( 'Rename', 'wptd' ) }
                                                </h3>
                                                <input
                                                        type="text"
                                                        className="wptd-prompt__input"
                                                        autoFocus
                                                        value={ dialog.value }
                                                        onChange={ ( e ) => setDialog( ( d ) => ( { ...d, value: e.target.value } ) ) }
                                                        onKeyDown={ ( e ) => {
                                                                if ( e.key === 'Enter' ) submitDialog();
                                                                if ( e.key === 'Escape' ) setDialog( null );
                                                        } }
                                                        placeholder={ dialog.mode === 'rename' ? __( 'New name', 'wptd' ) : __( 'e.g. includes/class-foo.php', 'wptd' ) }
                                                />
                                                <div className="wptd-prompt__actions">
                                                        <button className="wptd-btn wptd-btn--ghost" onClick={ () => setDialog( null ) }>{ __( 'Cancel', 'wptd' ) }</button>
                                                        <button className="wptd-btn wptd-btn--primary" onClick={ submitDialog }>
                                                                { dialog.mode === 'rename' ? __( 'Rename', 'wptd' ) : __( 'Create', 'wptd' ) }
                                                        </button>
                                                </div>
                                        </div>
                                </div>
                        ) }
                </div>
        );
}
