/**
 * ItemGrid.js — clean, spacious card grid.
 *
 * Each card shows ONLY the essentials a user needs at a glance:
 *   - Avatar with initials
 *   - Plugin/theme name
 *   - Status pill (Active / Inactive)
 *   - Download button
 *
 * Secondary metadata (version, size, modified) is rendered as a single
 * subtle line so the card stays calm and breathable — every extra detail
 * is optional, none of it is "required" to understand what the card is.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { formatSize, timeAgo, initials, colorFromSlug } from './utils';
import { Icon } from './Icon';

export default function ItemGrid( {
        items, type,
        selected, onToggleSelect,
        onDownload, onViewDetails,
} ) {
        const [ busy, setBusy ] = useState( {} );

        if ( items.length === 0 ) {
                return (
                        <div className="wptd-empty">
                                <div className="wptd-empty__icon">
                                        <Icon name="search" size={ 48 } strokeWidth={ 1.5 } />
                                </div>
                                <h3>{ __( 'No results found', 'wptd' ) }</h3>
                                <p>{ __( 'Try a different search term or filter.', 'wptd' ) }</p>
                        </div>
                );
        }

        const handleDownload = ( item ) => {
                setBusy( ( p ) => ( { ...p, [ item.slug ]: true } ) );
                onDownload( item, () => setBusy( ( p ) => ( { ...p, [ item.slug ]: false } ) ) );
        };

        return (
                <div className="wptd-grid">
                        { items.map( ( item, idx ) => {
                                const isBusy = !! busy[ item.slug ];
                                const isSelected = selected.has( item.slug );
                                const [ c1, c2 ] = colorFromSlug( item.slug );

                                return (
                                        <div
                                                key={ item.slug }
                                                role="button"
                                                tabIndex={ 0 }
                                                className={ `wptd-card ${ item.active ? 'is-active' : 'is-inactive' } ${ isSelected ? 'is-selected' : '' } ${ isBusy ? 'is-busy' : '' }` }
                                                style={ {
                                                        '--card-color-1': c1,
                                                        '--card-color-2': c2,
                                                        animationDelay: `${ Math.min( idx, 10 ) * 25 }ms`,
                                                } }
                                                onClick={ () => onToggleSelect( item.slug ) }
                                                onKeyDown={ ( e ) => {
                                                        if ( e.key === 'Enter' || e.key === ' ' ) {
                                                                e.preventDefault();
                                                                onToggleSelect( item.slug );
                                                        }
                                                } }
                                        >
                                                {/* Animated selected checkmark — the ONLY selection
                                                    affordance shown on the card. Clicking the card
                                                    toggles selection; no explicit checkbox is rendered
                                                    (kept the markup clean per the user's request). */}
                                                <span className="wptd-card__checkmark" aria-hidden="true">
                                                        <Icon name="check" size={ 14 } strokeWidth={ 3 } />
                                                </span>

                                                {/* Visually-hidden checkbox kept for screen-reader
                                                    accessibility — sighted users only see the animated
                                                    checkmark above. */}
                                                <label className="wptd-card__check wptd-sr-only" onClick={ ( e ) => e.stopPropagation() }>
                                                        <input
                                                                type="checkbox"
                                                                checked={ isSelected }
                                                                onChange={ () => onToggleSelect( item.slug ) }
                                                                aria-label={ __( 'Select', 'wptd' ) + ' ' + item.name }
                                                        />
                                                </label>

                                                {/* Header: avatar + name + status pill */}
                                                <div className="wptd-card__head">
                                                        <div className="wptd-card__avatar" aria-hidden="true">
                                                                { initials( item.name ) }
                                                        </div>
                                                        <div className="wptd-card__head-text">
                                                                <h3 className="wptd-card__name" title={ item.name }>{ item.name }</h3>
                                                                <span className={ `wptd-card__status ${ item.active ? 'is-on' : 'is-off' }` }>
                                                                        <span className="wptd-card__status-dot" />
                                                                        { item.active ? __( 'Active', 'wptd' ) : __( 'Inactive', 'wptd' ) }
                                                                </span>
                                                        </div>
                                                </div>

                                                {/* Optional meta line — kept subtle, never required */}
                                                <div className="wptd-card__meta">
                                                        { item.version && (
                                                                <span className="wptd-meta-item">
                                                                        <Icon name="tag" size={ 12 } />
                                                                        { __( 'v', 'wptd' ) }{ item.version }
                                                                </span>
                                                        ) }
                                                        { item.size > 0 && (
                                                                <span className="wptd-meta-item">
                                                                        <Icon name="hard-drive" size={ 12 } />
                                                                        { formatSize( item.size ) }
                                                                </span>
                                                        ) }
                                                        { item.modified > 0 && (
                                                                <span className="wptd-meta-item">
                                                                        <Icon name="clock" size={ 12 } />
                                                                        { timeAgo( item.modified ) }
                                                                </span>
                                                        ) }
                                                </div>

                                                {/* Single download button */}
                                                <button
                                                        className={ `wptd-card__btn ${ isBusy ? 'is-busy' : '' }` }
                                                        onClick={ ( e ) => { e.stopPropagation(); handleDownload( item ); } }
                                                        disabled={ isBusy }
                                                >
                                                        { isBusy ? (
                                                                <>
                                                                        <span className="wptd-btn-spinner wptd-btn-spinner--xs" />
                                                                        { __( 'Preparing…', 'wptd' ) }
                                                                </>
                                                        ) : (
                                                                <>
                                                                        <Icon name="download" size={ 15 } strokeWidth={ 2.4 } />
                                                                        { __( 'Download ZIP', 'wptd' ) }
                                                                </>
                                                        ) }
                                                </button>
                                        </div>
                                );
                        } ) }
                </div>
        );
}
