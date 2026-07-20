/**
 * SettingsPanel.js — slide-over settings drawer.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { saveSettings } from '../api/download';
import { toast } from './Toast';
import { Icon } from './Icon';
import Select from './Select';

export default function SettingsPanel( { open, settings, onClose, onSave } ) {
        const [ draft, setDraft ] = useState( settings );
        const [ saving, setSaving ] = useState( false );

        useEffect( () => { setDraft( settings ); }, [ settings ] );

        useEffect( () => {
                const h = ( e ) => { if ( e.key === 'Escape' && open ) onClose(); };
                window.addEventListener( 'keydown', h );
                return () => window.removeEventListener( 'keydown', h );
        }, [ open, onClose ] );

        if ( ! open ) return null;

        const update = ( key, value ) => setDraft( ( d ) => ( { ...d, [ key ]: value } ) );

        const handleSave = async () => {
                setSaving( true );
                try {
                        const next = await saveSettings( draft );
                        onSave( next );
                        toast( __( 'Settings saved.', 'wptd' ), 'success' );
                        onClose();
                } catch ( err ) {
                        toast( err.message || __( 'Failed to save settings.', 'wptd' ), 'error' );
                } finally {
                        setSaving( false );
                }
        };

        return (
                <div className="wptd-modal-overlay" onClick={ onClose }>
                        <aside
                                className="wptd-modal wptd-modal--settings"
                                onClick={ ( e ) => e.stopPropagation() }
                                role="dialog"
                                aria-modal="true"
                                aria-label={ __( 'Settings', 'wptd' ) }
                        >
                                <div className="wptd-modal__header">
                                        <div className="wptd-modal__title-block">
                                                <h2 className="wptd-modal__title">{ __( 'Settings', 'wptd' ) }</h2>
                                                <span className="wptd-modal__sub">{ __( 'Personalize your downloader', 'wptd' ) }</span>
                                        </div>
                                        <button className="wptd-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'wptd' ) }>✕</button>
                                </div>

                                <div className="wptd-modal__body">
                                        <div className="wptd-setting-row">
                                                <div className="wptd-setting-row__label">
                                                        <strong>{ __( 'Default view', 'wptd' ) }</strong>
                                                        <span>{ __( 'Layout used on first load', 'wptd' ) }</span>
                                                </div>
                                                <div className="wptd-segmented">
                                                        <button className={ `wptd-seg ${ ( draft.default_view || 'grid' ) === 'grid' ? 'is-active' : '' }` } onClick={ () => update( 'default_view', 'grid' ) }>{ __( 'Grid', 'wptd' ) }</button>
                                                        <button className={ `wptd-seg ${ draft.default_view === 'table' ? 'is-active' : '' }` } onClick={ () => update( 'default_view', 'table' ) }>{ __( 'Table', 'wptd' ) }</button>
                                                </div>
                                        </div>

                                        <div className="wptd-setting-row">
                                                <div className="wptd-setting-row__label">
                                                        <strong>{ __( 'Default sort', 'wptd' ) }</strong>
                                                        <span>{ __( 'How items are ordered by default', 'wptd' ) }</span>
                                                </div>
                                                <Select
                                                        value={ draft.default_sort || 'name' }
                                                        onChange={ ( v ) => update( 'default_sort', v ) }
                                                        ariaLabel={ __( 'Default sort', 'wptd' ) }
                                                        icon="sort"
                                                        options={ [
                                                                { value: 'name', label: __( 'Name (A-Z)', 'wptd' ) },
                                                                { value: 'size', label: __( 'Size (largest first)', 'wptd' ) },
                                                                { value: 'modified', label: __( 'Recently modified', 'wptd' ) },
                                                                { value: 'status', label: __( 'Active first', 'wptd' ) },
                                                        ] }
                                                />
                                        </div>

                                        <div className="wptd-setting-row">
                                                <div className="wptd-setting-row__label">
                                                        <strong>{ __( 'Show inactive items', 'wptd' ) }</strong>
                                                        <span>{ __( 'Hide to focus only on what is running', 'wptd' ) }</span>
                                                </div>
                                                <label className="wptd-switch">
                                                        <input type="checkbox" checked={ draft.show_inactive !== false } onChange={ ( e ) => update( 'show_inactive', e.target.checked ) } />
                                                        <span className="wptd-switch__track"><span className="wptd-switch__thumb" /></span>
                                                </label>
                                        </div>

                                        <div className="wptd-setting-row">
                                                <div className="wptd-setting-row__label">
                                                        <strong>{ __( 'Remember layout', 'wptd' ) }</strong>
                                                        <span>{ __( 'Save your view & sort in this browser', 'wptd' ) }</span>
                                                </div>
                                                <label className="wptd-switch">
                                                        <input type="checkbox" checked={ draft.remember_layout !== false } onChange={ ( e ) => update( 'remember_layout', e.target.checked ) } />
                                                        <span className="wptd-switch__track"><span className="wptd-switch__thumb" /></span>
                                                </label>
                                        </div>

                                        <div className="wptd-setting-row wptd-setting-row--info">
                                                <span className="wptd-info-icon"><Icon name="info" size={ 14 } /></span>
                                                <span>
                                                        { __( 'All settings are stored in the WordPress options table and apply to all administrators.', 'wptd' ) }
                                                </span>
                                        </div>
                                </div>

                                <div className="wptd-modal__footer">
                                        <button className="wptd-btn wptd-btn--ghost" onClick={ onClose }>{ __( 'Cancel', 'wptd' ) }</button>
                                        <button className="wptd-btn wptd-btn--primary" onClick={ handleSave } disabled={ saving }>
                                                { saving ? <span className="wptd-btn-spinner" /> : null }
                                                { __( 'Save settings', 'wptd' ) }
                                        </button>
                                </div>
                        </aside>
                </div>
        );
}
