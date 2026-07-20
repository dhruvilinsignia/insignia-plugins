/**
 * Header.js — page header with branding, tabs, search, view switch.
 *
 * Branding: uses the custom InsigniaLogo (heraldic shield + compass) and
 * shows the product name "Insignia Explorer" with a small subtitle.
 * Two decorative animated blobs drift behind the header for a polished,
 * modern feel — they're aria-hidden and pointer-events:none so they
 * never interfere with the controls.
 */
import { __ } from '@wordpress/i18n';
import { useState, useRef, useEffect } from '@wordpress/element';
import { Icon, InsigniaLogo } from './Icon';

export default function Header( {
        search, onSearch,
        activeTab, onTabChange,
        pluginCount, themeCount,
        view, onViewChange,
        onOpenSettings,
        onOpenChanges,
        historyCount,
        mode, onModeChange,
} ) {
        const searchRef = useRef( null );

        useEffect( () => {
                const handler = ( e ) => {
                        if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
                                e.preventDefault();
                                searchRef.current?.focus();
                        }
                        if ( e.key === 'Escape' && document.activeElement === searchRef.current ) {
                                searchRef.current?.blur();
                        }
                };
                window.addEventListener( 'keydown', handler );
                return () => window.removeEventListener( 'keydown', handler );
        }, [] );

        return (
                <div className="wptd-header">
                        {/* Decorative animated background shapes — never interactive */}
                        <div className="wptd-header__aurora" aria-hidden="true">
                                <span className="wptd-blob wptd-blob--1" />
                                <span className="wptd-blob wptd-blob--2" />
                                <span className="wptd-blob wptd-blob--3" />
                                <span className="wptd-grid-overlay" />
                        </div>

                        <div className="wptd-header__top">
                                <div className="wptd-brand">
                                        <span className="wptd-brand__icon">
                                                <InsigniaLogo size={ 40 } />
                                        </span>
                                        <div>
                                                <h1 className="wptd-brand__title">
                                                        { __( 'Insignia Explorer', 'wptd' ) }
                                                        <span className="wptd-brand__ver">v{ window.WPTDData?.version || '1.0.1' }</span>
                                                </h1>
                                                <p className="wptd-brand__sub">
                                                        { __( 'Download & edit any plugin, theme, or WordPress root file', 'wptd' ) }
                                                </p>
                                        </div>
                                </div>

                                <div className="wptd-header__actions">
                                        <div className="wptd-header__search">
                                                <span className="wptd-search-icon">
                                                        <Icon name="search" size={ 16 } strokeWidth={ 2 } />
                                                </span>
                                                <input
                                                        ref={ searchRef }
                                                        type="search"
                                                        className="wptd-search-input"
                                                        value={ search }
                                                        onChange={ ( e ) => onSearch( e.target.value ) }
                                                        placeholder={ __( 'Search by name or slug…', 'wptd' ) }
                                                />
                                                { search ? (
                                                        <button
                                                                className="wptd-search-clear"
                                                                onClick={ () => onSearch( '' ) }
                                                                aria-label={ __( 'Clear search', 'wptd' ) }
                                                        >
                                                                <Icon name="close" size={ 13 } />
                                                        </button>
                                                ) : (
                                                        <span className="wptd-search-kbd">Ctrl K</span>
                                                ) }
                                        </div>

                                        <button
                                                className="wptd-icon-btn"
                                                onClick={ () => onViewChange( view === 'grid' ? 'table' : 'grid' ) }
                                                title={ view === 'grid' ? __( 'Switch to table view', 'wptd' ) : __( 'Switch to grid view', 'wptd' ) }
                                                aria-label={ __( 'Toggle view', 'wptd' ) }
                                        >
                                                <Icon name={ view === 'grid' ? 'list' : 'grid' } size={ 16 } />
                                        </button>

                                        <button
                                                className="wptd-icon-btn"
                                                onClick={ onOpenChanges }
                                                title={ __( 'Recent file changes', 'wptd' ) }
                                                aria-label={ __( 'Recent file changes', 'wptd' ) }
                                        >
                                                <Icon name="code" size={ 16 } />
                                        </button>

                                        <button
                                                className="wptd-icon-btn"
                                                onClick={ onOpenSettings }
                                                title={ __( 'Settings', 'wptd' ) }
                                                aria-label={ __( 'Settings', 'wptd' ) }
                                        >
                                                <Icon name="settings" size={ 16 } />
                                        </button>
                                </div>
                        </div>

                        <nav className="wptd-tabs">
                                <button
                                        className={ `wptd-tab ${ activeTab === 'plugins' ? 'is-active' : '' }` }
                                        onClick={ () => onTabChange( 'plugins' ) }
                                >
                                        <Icon name="puzzle" size={ 15 } />
                                        { __( 'Plugins', 'wptd' ) }
                                        <span className="wptd-tab__count">{ pluginCount }</span>
                                </button>
                                <button
                                        className={ `wptd-tab ${ activeTab === 'themes' ? 'is-active' : '' }` }
                                        onClick={ () => onTabChange( 'themes' ) }
                                >
                                        <Icon name="palette" size={ 15 } />
                                        { __( 'Themes', 'wptd' ) }
                                        <span className="wptd-tab__count">{ themeCount }</span>
                                </button>
                                <div className="wptd-tabs__spacer" />
                                <div className="wptd-mode-switch">
                                        <button
                                                className={ `wptd-mode-btn ${ mode === 'downloader' ? 'is-active' : '' }` }
                                                onClick={ () => onModeChange( 'downloader' ) }
                                                title={ __( 'Downloader mode', 'wptd' ) }
                                        >
                                                <Icon name="download" size={ 14 } />
                                                { __( 'Downloader', 'wptd' ) }
                                        </button>
                                        <button
                                                className={ `wptd-mode-btn ${ mode === 'editor' ? 'is-active' : '' }` }
                                                onClick={ () => onModeChange( 'editor' ) }
                                                title={ __( 'Code editor mode', 'wptd' ) }
                                        >
                                                <Icon name="code" size={ 14 } />
                                                { __( 'Code Editor', 'wptd' ) }
                                        </button>
                                </div>
                                <span className="wptd-tab__hint">
                                        { mode === 'editor'
                                                ? __( 'Ctrl+Shift+F search · Ctrl+S save', 'wptd' )
                                                : historyCount > 0
                                                        ? `${ historyCount } ${ __( 'downloads this session', 'wptd' ) }`
                                                        : __( 'Tip: Ctrl/Cmd-click to multi-select', 'wptd' )
                                                }
                                </span>
                        </nav>
                </div>
        );
}
