/**
 * App.js — root component for Insignia WordPress Explorer.
 *
 * Features
 *  - Grid / table view toggle (persisted)
 *  - Sort: name, size, modified, status
 *  - Status filter: all / active / inactive
 *  - Multi-select with bulk download
 *  - Item detail modal with file tree
 *  - Download history panel
 *  - Settings panel
 *  - Toast notifications
 *  - Loading skeletons
 *  - Smooth animations everywhere
 */
import { useState, useEffect, useCallback, useMemo } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import Header from './Header';
import StatsBar from './StatsBar';
import Toolbar from './Toolbar';
import ItemGrid from './ItemGrid';
import ItemTable from './ItemTable';
import DetailsModal from './DetailsModal';
import SettingsPanel from './SettingsPanel';
import HistoryPanel from './HistoryPanel';
import Skeletons from './Skeletons';
import CodeEditorView from './CodeEditorView';
import ToastHost, { toast } from './Toast';
import ConfirmDialogHost from './ConfirmDialog';
import RecentChangesPanel from './RecentChangesPanel';
import { Icon } from './Icon';
import {
        triggerDownload,
        bulkDownload,
        saveBlob,
        addHistory,
        fetchHistory,
} from '../api/download';

apiFetch.use( apiFetch.createNonceMiddleware( window.WPTDData?.nonce ) );

const LS_KEYS = {
        view:    'wptd:view',
        sort:    'wptd:sort',
        status:  'wptd:status',
};

function sortItems( list, sort ) {
        const arr = [ ...list ];
        switch ( sort ) {
                case 'name':       return arr.sort( ( a, b ) => a.name.localeCompare( b.name ) );
                case 'name-desc':  return arr.sort( ( a, b ) => b.name.localeCompare( a.name ) );
                case 'size':       return arr.sort( ( a, b ) => ( b.size || 0 ) - ( a.size || 0 ) );
                case 'size-asc':   return arr.sort( ( a, b ) => ( a.size || 0 ) - ( b.size || 0 ) );
                case 'modified':   return arr.sort( ( a, b ) => ( b.modified || 0 ) - ( a.modified || 0 ) );
                case 'status':     return arr.sort( ( a, b ) => ( b.active === a.active ) ? 0 : ( b.active ? 1 : -1 ) );
                default:           return arr;
        }
}

export default function App() {
        // ── Data state
        const [ data, setData ]               = useState( null );
        const [ error, setError ]             = useState( null );
        const [ loading, setLoading ]         = useState( true );

        // ── UI state
        const [ activeTab, setActiveTab ]     = useState( 'plugins' );
        const [ mode, setMode ]               = useState( 'downloader' ); // 'downloader' | 'editor'
        const [ search, setSearch ]           = useState( '' );
        const [ view, setView ]               = useState(
                localStorage.getItem( LS_KEYS.view ) || window.WPTDData?.settings?.default_view || 'grid'
        );
        const [ sort, setSort ]               = useState(
                localStorage.getItem( LS_KEYS.sort ) || window.WPTDData?.settings?.default_sort || 'name'
        );
        const [ statusFilter, setStatusFilter ] = useState( 'all' );

        // ── Selection
        const [ selected, setSelected ]       = useState( new Set() );

        // ── Panels & modals
        const [ detailItem, setDetailItem ]   = useState( null );
        const [ settingsOpen, setSettingsOpen ] = useState( false );
        const [ historyOpen, setHistoryOpen ] = useState( false );
        const [ historyRefresh, setHistoryRefresh ] = useState( 0 );
        const [ changesOpen, setChangesOpen ] = useState( false );

        // ── Settings sync
        const [ settings, setSettings ]       = useState( window.WPTDData?.settings || {} );

        // ── History count badge
        const [ historyCount, setHistoryCount ] = useState( 0 );

        // ── Bulk download progress
        const [ bulkBusy, setBulkBusy ] = useState( false );

        // ── Fetch data once on mount
        useEffect( () => {
                apiFetch( { path: '/wptd/v1/list' } )
                        .then( ( res ) => { setData( res ); setLoading( false ); } )
                        .catch( ( err ) => { setError( err.message || __( 'Failed to load.', 'wptd' ) ); setLoading( false ); } );
        }, [] );

        // ── Fetch real history count from server on mount (so the badge
        //     reflects the actual persisted history, not just this session).
        useEffect( () => {
                fetchHistory()
                        .then( ( items ) => {
                                if ( Array.isArray( items ) ) {
                                        setHistoryCount( items.length );
                                }
                        } )
                        .catch( () => {} );
        }, [] );

        // ── Persist view & sort
        useEffect( () => {
                if ( settings.remember_layout !== false ) {
                        localStorage.setItem( LS_KEYS.view, view );
                        localStorage.setItem( LS_KEYS.sort, sort );
                }
        }, [ view, sort, settings.remember_layout ] );

        // ── Reset selection when switching tabs
        const handleTabChange = ( tab ) => {
                setActiveTab( tab );
                setSearch( '' );
                setSelected( new Set() );
        };

        const items = useMemo(
                () => data ? ( activeTab === 'plugins' ? data.plugins : data.themes ) : [],
                [ data, activeTab ]
        );

        const filtered = useMemo( () => {
                let list = items;
                if ( statusFilter === 'active' )   list = list.filter( ( i ) => i.active );
                if ( statusFilter === 'inactive' ) list = list.filter( ( i ) => ! i.active );
                if ( settings.show_inactive === false && statusFilter === 'all' ) {
                        list = list.filter( ( i ) => i.active );
                }
                const q = search.trim().toLowerCase();
                if ( q ) {
                        list = list.filter( ( i ) =>
                                i.name.toLowerCase().includes( q ) ||
                                i.slug.toLowerCase().includes( q ) ||
                                ( i.author || '' ).toLowerCase().includes( q )
                        );
                }
                return sortItems( list, sort );
        }, [ items, search, statusFilter, sort, settings.show_inactive ] );

        // ── Selection helpers
        const toggleSelect = useCallback( ( slug ) => {
                setSelected( ( prev ) => {
                        const next = new Set( prev );
                        if ( next.has( slug ) ) next.delete( slug );
                        else next.add( slug );
                        return next;
                } );
        }, [] );

        const clearSelection = () => setSelected( new Set() );

        // ── Single download (records history)
        const handleDownload = ( item, done ) => {
                triggerDownload( activeTab === 'plugins' ? 'plugin' : 'theme', item.slug );
                toast( __( 'Preparing', 'wptd' ) + ' ' + item.name + '.zip…', 'info' );
                // Record in history and surface any failure.
                addHistory( {
                        type: activeTab === 'plugins' ? 'plugin' : 'theme',
                        slug: item.slug,
                        name: item.name,
                        version: item.version || '',
                        size: item.size || 0,
                } )
                        .then( () => {
                                // Refresh the real count from the server after a
                                // successful POST so the badge always matches
                                // what the HistoryPanel will show.
                                fetchHistory()
                                        .then( ( items ) => {
                                                if ( Array.isArray( items ) ) {
                                                        setHistoryCount( items.length );
                                                }
                                        } )
                                        .catch( () => {} );
                        } )
                        .catch( ( err ) => {
                                // Don't silently swallow — show the user something
                                // went wrong recording the history entry.
                                toast(
                                        __( 'Could not save download history: ', 'wptd' ) + ( err?.message || '' ),
                                        'error',
                                        6000
                                );
                        } );
                // optimistic reset
                setTimeout( done, 2500 );
        };

        // ── Bulk download
        const handleBulkDownload = async () => {
                const picked = filtered.filter( ( i ) => selected.has( i.slug ) );
                if ( picked.length === 0 ) return;

                setBulkBusy( true );
                toast( __( 'Building bulk ZIP with', 'wptd' ) + ' ' + picked.length + ' ' + __( 'items…', 'wptd' ), 'info' );

                try {
                        const blob = await bulkDownload( picked.map( ( i ) => ( {
                                type: activeTab === 'plugins' ? 'plugin' : 'theme',
                                slug: i.slug,
                        } ) ) );
                        const filename = 'wptd-bulk-' + new Date().toISOString().slice( 0, 19 ).replace( /[:T]/g, '-' ) + '.zip';
                        saveBlob( blob, filename );

                        toast( __( 'Bulk ZIP downloaded:', 'wptd' ) + ' ' + picked.length + ' ' + __( 'items', 'wptd' ), 'success', 6000 );

                        // record history for each
                        await Promise.all( picked.map( ( i ) =>
                                addHistory( {
                                        type: activeTab === 'plugins' ? 'plugin' : 'theme',
                                        slug: i.slug,
                                        name: i.name,
                                        version: i.version || '',
                                        size: i.size || 0,
                                } ).catch( () => {} )
                        ) );
                        // Refresh real count from server after the POSTs land.
                        fetchHistory()
                                .then( ( items ) => {
                                        if ( Array.isArray( items ) ) {
                                                setHistoryCount( items.length );
                                        }
                                } )
                                .catch( () => {} );

                        clearSelection();
                } catch ( err ) {
                        toast( err.message || __( 'Bulk download failed.', 'wptd' ), 'error', 6000 );
                } finally {
                        setBulkBusy( false );
                }
        };

        const handleSettingsSave = ( next ) => {
                setSettings( next );
                if ( next.default_view && ! localStorage.getItem( LS_KEYS.view ) ) setView( next.default_view );
                if ( next.default_sort && ! localStorage.getItem( LS_KEYS.sort ) ) setSort( next.default_sort );
        };

        const openHistory = () => {
                setHistoryOpen( true );
                setHistoryRefresh( ( k ) => k + 1 );
        };

        return (
                <div className="wptd-page">
                        <Header
                                search={ search }
                                onSearch={ setSearch }
                                activeTab={ activeTab }
                                onTabChange={ handleTabChange }
                                pluginCount={ data?.plugins?.length ?? 0 }
                                themeCount={ data?.themes?.length ?? 0 }
                                view={ view }
                                onViewChange={ setView }
                                onOpenSettings={ () => setSettingsOpen( true ) }
                                onOpenChanges={ () => setChangesOpen( true ) }
                                historyCount={ historyCount }
                                mode={ mode }
                                onModeChange={ setMode }
                        />

                        <div className="wptd-body">
                                { mode === 'editor' && ! loading && ! error && (
                                        <CodeEditorView items={ items } activeTab={ activeTab } />
                                ) }

                                { mode === 'downloader' && loading && (
                                        <>
                                                <div className="wptd-stats">
                                                        { Array.from( { length: 4 } ).map( ( _, i ) => (
                                                                <div className="wptd-stat wptd-stat--skeleton" key={ i }>
                                                                        <div className="wptd-skeleton-box wptd-skeleton--avatar" />
                                                                        <div className="wptd-stat__main">
                                                                                <div className="wptd-skeleton-line wptd-skeleton--lg" />
                                                                                <div className="wptd-skeleton-line wptd-skeleton--sm" />
                                                                        </div>
                                                                </div>
                                                        ) ) }
                                                </div>
                                                <Skeletons view={ view } />
                                        </>
                                ) }

                                { mode === 'downloader' && error && (
                                        <div className="wptd-error-state">
                                                <div className="wptd-error-state__icon"><Icon name="alert-circle" size={ 40 } /></div>
                                                <h3>{ __( 'Something went wrong', 'wptd' ) }</h3>
                                                <p>{ error }</p>
                                                <button className="wptd-btn wptd-btn--primary" onClick={ () => location.reload() }>
                                                        { __( 'Reload', 'wptd' ) }
                                                </button>
                                        </div>
                                ) }

                                { mode === 'downloader' && ! loading && ! error && (
                                        <>
                                                <StatsBar
                                                        items={ items }
                                                        filtered={ filtered }
                                                        type={ activeTab }
                                                        selectedCount={ selected.size }
                                                />

                                                <Toolbar
                                                        sort={ sort }
                                                        onSortChange={ setSort }
                                                        statusFilter={ statusFilter }
                                                        onStatusFilterChange={ setStatusFilter }
                                                        selectedCount={ selected.size }
                                                        onBulkDownload={ handleBulkDownload }
                                                        onClearSelection={ clearSelection }
                                                        total={ items.length }
                                                        shown={ filtered.length }
                                                />

                                                { view === 'grid' ? (
                                                        <ItemGrid
                                                                items={ filtered }
                                                                type={ activeTab === 'plugins' ? 'plugin' : 'theme' }
                                                                selected={ selected }
                                                                onToggleSelect={ toggleSelect }
                                                                onDownload={ handleDownload }
                                                        />
                                                ) : (
                                                        <ItemTable
                                                                items={ filtered }
                                                                type={ activeTab === 'plugins' ? 'plugin' : 'theme' }
                                                                selected={ selected }
                                                                onToggleSelect={ toggleSelect }
                                                                onDownload={ handleDownload }
                                                                onViewDetails={ setDetailItem }
                                                        />
                                                ) }

                                                <div className="wptd-footer-bar">
                                                        <button className="wptd-link-btn" onClick={ openHistory }>
                                                                <span className="wptd-link-btn__icon"><Icon name="clock" size={ 14 } /></span>
                                                                { __( 'Download history', 'wptd' ) }
                                                                { historyCount > 0 && <span className="wptd-link-btn__count">{ historyCount }</span> }
                                                        </button>
                                                        <button className="wptd-link-btn" onClick={ () => setChangesOpen( true ) }>
                                                                <span className="wptd-link-btn__icon"><Icon name="code" size={ 14 } /></span>
                                                                { __( 'Recent file changes', 'wptd' ) }
                                                        </button>
                                                        <span className="wptd-footer-bar__info">
                                                                { window.WPTDData?.server?.wp_version
                                                                        ? `WP ${ data?.server?.wp_version || '' } · PHP ${ data?.server?.php_version || '' }`
                                                                        : `Insignia Explorer v${ window.WPTDData?.version || '1.0.0' }`
                                                                }
                                                        </span>
                                                </div>
                                        </>
                                ) }
                        </div>

                        { detailItem && (
                                <DetailsModal
                                        item={ detailItem }
                                        type={ activeTab === 'plugins' ? 'plugin' : 'theme' }
                                        onClose={ () => setDetailItem( null ) }
                                />
                        ) }

                        <SettingsPanel
                                open={ settingsOpen }
                                settings={ settings }
                                onClose={ () => setSettingsOpen( false ) }
                                onSave={ handleSettingsSave }
                        />

                        <HistoryPanel
                                open={ historyOpen }
                                onClose={ () => setHistoryOpen( false ) }
                                refreshKey={ historyRefresh }
                        />

                        <RecentChangesPanel
                                open={ changesOpen }
                                onClose={ () => setChangesOpen( false ) }
                        />

                        <ToastHost />
                        <ConfirmDialogHost />

                        { bulkBusy && (
                                <div className="wptd-busy-overlay">
                                        <div className="wptd-busy-card">
                                                <span className="wptd-btn-spinner wptd-btn-spinner--lg" />
                                                <p>{ __( 'Building bulk ZIP…', 'wptd' ) }</p>
                                        </div>
                                </div>
                        ) }
                </div>
        );
}
