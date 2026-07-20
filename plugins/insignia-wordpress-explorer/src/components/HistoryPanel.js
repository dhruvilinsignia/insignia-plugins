/**
 * HistoryPanel.js — slide-over showing the persisted download history.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { fetchHistory, clearHistory, triggerDownload } from '../api/download';
import { formatSize, timeAgo } from './utils';
import { Icon } from './Icon';
import { toast } from './Toast';
import { confirmDialog } from './ConfirmDialog';

export default function HistoryPanel( { open, onClose, refreshKey } ) {
        const [ items, setItems ]   = useState( [] );
        const [ loading, setLoading ] = useState( false );
        const [ clearing, setClearing ] = useState( false );

        useEffect( () => {
                if ( ! open ) return;
                setLoading( true );
                fetchHistory()
                        .then( ( res ) => { setItems( res ); setLoading( false ); } )
                        .catch( () => setLoading( false ) );
        }, [ open, refreshKey ] );

        useEffect( () => {
                const h = ( e ) => { if ( e.key === 'Escape' && open ) onClose(); };
                window.addEventListener( 'keydown', h );
                return () => window.removeEventListener( 'keydown', h );
        }, [ open, onClose ] );

        if ( ! open ) return null;

        const handleClear = async () => {
                const ok = await confirmDialog( {
                        title: __( 'Clear all download history?', 'wptd' ),
                        message: __(
                                'Every entry in your download history will be permanently removed. The downloaded files themselves are not affected.',
                                'wptd'
                        ),
                        confirmLabel: __( 'Clear all', 'wptd' ),
                        cancelLabel: __( 'Keep history', 'wptd' ),
                        variant: 'danger',
                        icon: 'trash',
                } );
                if ( ! ok ) return;
                setClearing( true );
                try {
                        await clearHistory();
                        setItems( [] );
                        toast( __( 'History cleared.', 'wptd' ), 'success' );
                } catch ( err ) {
                        toast( err.message || __( 'Failed to clear history.', 'wptd' ), 'error' );
                } finally {
                        setClearing( false );
                }
        };

        const handleRedownload = ( item ) => {
                triggerDownload( item.type, item.slug );
                toast( __( 'Re-downloading', 'wptd' ) + ' ' + item.name + '…', 'info' );
        };

        return (
                <div className="wptd-modal-overlay" onClick={ onClose }>
                        <aside
                                className="wptd-modal wptd-modal--history"
                                onClick={ ( e ) => e.stopPropagation() }
                                role="dialog"
                                aria-modal="true"
                                aria-label={ __( 'Download history', 'wptd' ) }
                        >
                                <div className="wptd-modal__header">
                                        <div className="wptd-modal__title-block">
                                                <h2 className="wptd-modal__title">{ __( 'Download history', 'wptd' ) }</h2>
                                                <span className="wptd-modal__sub">
                                                        { items.length > 0
                                                                ? `${ items.length } ${ __( 'recent downloads', 'wptd' ) }`
                                                                : __( 'No downloads yet' ) }
                                                </span>
                                        </div>
                                        <button className="wptd-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'wptd' ) }>✕</button>
                                </div>

                                <div className="wptd-modal__body">
                                        { loading && (
                                                <div className="wptd-history-loading">
                                                        <div className="wptd-skeleton-line" />
                                                        <div className="wptd-skeleton-line" />
                                                        <div className="wptd-skeleton-line wptd-skeleton--sm" />
                                                </div>
                                        ) }

                                        { ! loading && items.length === 0 && (
                                                <div className="wptd-empty wptd-empty--inline">
                                                        <div className="wptd-empty__icon"><Icon name="archive" size={ 48 } /></div>
                                                        <h3>{ __( 'No history yet', 'wptd' ) }</h3>
                                                        <p>{ __( 'Your last 50 downloads will appear here for quick re-download.', 'wptd' ) }</p>
                                                </div>
                                        ) }

                                        { ! loading && items.length > 0 && (
                                                <ul className="wptd-history-list">
                                                        { items.map( ( h ) => (
                                                                <li key={ h.id } className="wptd-history-item">
                                                                        <div className={ `wptd-history-item__icon ${ h.type }` }>
                                                                                <Icon name={ h.type === 'plugin' ? 'puzzle' : 'palette' } size={ 16 } />
                                                                        </div>
                                                                        <div className="wptd-history-item__body">
                                                                                <span className="wptd-history-item__name">{ h.name }</span>
                                                                                <span className="wptd-history-item__meta">
                                                                                        <code>{ h.slug }</code>
                                                                                        { h.version && <span>· v{ h.version }</span> }
                                                                                        { h.size > 0 && <span>· { h.size_human }</span> }
                                                                                        <span>· { timeAgo( h.timestamp ) }</span>
                                                                                </span>
                                                                        </div>
                                                                        <button
                                                                                className="wptd-row-btn"
                                                                                title={ __( 'Download again', 'wptd' ) }
                                                                                onClick={ () => handleRedownload( h ) }
                                                                        >
                                                                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
                                                                                        <path d="M12 3v12" /><polyline points="6 11 12 17 18 11" /><path d="M5 21h14" />
                                                                                </svg>
                                                                        </button>
                                                                </li>
                                                        ) ) }
                                                </ul>
                                        ) }
                                </div>

                                { items.length > 0 && (
                                        <div className="wptd-modal__footer">
                                                <button className="wptd-btn wptd-btn--ghost" onClick={ onClose }>{ __( 'Close', 'wptd' ) }</button>
                                                <button className="wptd-btn wptd-btn--danger" onClick={ handleClear } disabled={ clearing }>
                                                        { clearing ? <span className="wptd-btn-spinner" /> : null }
                                                        { __( 'Clear history', 'wptd' ) }
                                                </button>
                                        </div>
                                ) }
                        </aside>
                </div>
        );
}
