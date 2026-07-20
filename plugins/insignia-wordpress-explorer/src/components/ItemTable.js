/**
 * ItemTable.js — compact table view with the same selection & download
 * affordances as the grid view.
 *
 * Design
 * ──────
 * The table is wrapped in a card with a sticky header. Each row has:
 *   • checkbox (selection)
 *   • name + description (primary column)
 *   • status pill (active / inactive)
 *   • version chip
 *   • size + file count (compact)
 *   • modified time
 *   • actions (info + download)
 *
 * Rows have a subtle hover state, a left accent for active items, and a
 * selection highlight. On narrow screens, the slug column is hidden and
 * the row wraps cleanly.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { formatSize, timeAgo } from './utils';
import { Icon } from './Icon';

export default function ItemTable( {
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
				<p>{ __( 'Try a different search term, or clear the active filter.', 'wptd' ) }</p>
			</div>
		);
	}

	const handleDownload = ( item ) => {
		setBusy( ( p ) => ( { ...p, [ item.slug ]: true } ) );
		onDownload( item, () => setBusy( ( p ) => ( { ...p, [ item.slug ]: false } ) ) );
	};

	const allSelected = items.length > 0 && items.every( ( i ) => selected.has( i.slug ) );
	const someSelected = ! allSelected && items.some( ( i ) => selected.has( i.slug ) );

	return (
		<div className="wptd-table-wrap">
			<table className="wptd-table">
				<thead>
						<tr>
								<th className="wptd-col-check">
										<label className="wptd-checkbox-cell" title={ allSelected ? __( 'Deselect all', 'wptd' ) : __( 'Select all', 'wptd' ) }>
												<input
														type="checkbox"
														aria-label={ __( 'Select all', 'wptd' ) }
														checked={ allSelected }
														ref={ ( el ) => { if ( el ) el.indeterminate = someSelected; } }
														onChange={ () => {
																items.forEach( ( i ) => {
																		const sel = selected.has( i.slug );
																		if ( sel === allSelected ) onToggleSelect( i.slug );
																} );
														} }
												/>
												<span className="wptd-checkbox-cell__box" />
										</label>
								</th>
								<th className="wptd-col-name">{ __( 'Name', 'wptd' ) }</th>
								<th className="wptd-col-version">{ __( 'Version', 'wptd' ) }</th>
								<th className="wptd-col-size">{ __( 'Size', 'wptd' ) }</th>
								<th className="wptd-col-status">{ __( 'Status', 'wptd' ) }</th>
								<th className="wptd-col-mtime">{ __( 'Modified', 'wptd' ) }</th>
								<th className="wptd-col-actions">{ __( 'Actions', 'wptd' ) }</th>
						</tr>
				</thead>
				<tbody>
						{ items.map( ( item ) => {
								const isBusy = !! busy[ item.slug ];
								const isSelected = selected.has( item.slug );
								return (
										<tr
												key={ item.slug }
												className={ `${ isSelected ? 'is-selected' : '' } ${ item.active ? 'is-active' : '' }` }
										>
												<td className="wptd-col-check">
														<label className="wptd-checkbox-cell" title={ __( 'Select', 'wptd' ) + ' ' + item.name }>
																<input
																		type="checkbox"
																		checked={ isSelected }
																		onChange={ () => onToggleSelect( item.slug ) }
																		aria-label={ __( 'Select', 'wptd' ) + ' ' + item.name }
																/>
																<span className="wptd-checkbox-cell__box" />
														</label>
												</td>
												<td className="wptd-col-name">
														<div className="wptd-col-name__row">
																<span className={ `wptd-col-name__mark ${ item.active ? 'is-active' : 'is-inactive' }` } title={ item.active ? __( 'Active', 'wptd' ) : __( 'Inactive', 'wptd' ) }>
																		<Icon name={ type === 'plugin' ? 'puzzle' : 'palette' } size={ 14 } />
																</span>
																<div className="wptd-col-name__text">
																		<strong>{ item.name }</strong>
																		{ item.description && (
																				<span className="wptd-col-desc">{ item.description }</span>
																		) }
																		<span className="wptd-col-slug-line">
																				<code>{ item.slug }</code>
																				{ item.author && <span className="wptd-col-author">· { item.author }</span> }
																		</span>
																</div>
														</div>
												</td>
												<td className="wptd-col-version">
														{ item.version ? (
																<span className="wptd-version-chip">v{ item.version }</span>
														) : (
																<span className="wptd-muted-dash">—</span>
														) }
												</td>
												<td className="wptd-col-size">
														{ item.size > 0 ? (
																<div className="wptd-size-cell">
																		<span className="wptd-size-cell__value">{ formatSize( item.size ) }</span>
																		{ item.file_count > 0 && (
																				<span className="wptd-size-cell__count">{ item.file_count } { __( 'files', 'wptd' ) }</span>
																		) }
																</div>
														) : (
																<span className="wptd-muted-dash">—</span>
														) }
												</td>
												<td className="wptd-col-status">
														<span className={ `wptd-pill ${ item.active ? 'wptd-pill--active' : 'wptd-pill--inactive' }` }>
																<span className="wptd-pill__dot" />
																{ item.active ? __( 'Active', 'wptd' ) : __( 'Inactive', 'wptd' ) }
														</span>
												</td>
												<td className="wptd-col-mtime">
														{ item.modified ? (
																<span title={ new Date( item.modified * 1000 ).toLocaleString() }>
																		{ timeAgo( item.modified ) }
																</span>
														) : (
																<span className="wptd-muted-dash">—</span>
														) }
												</td>
												<td className="wptd-col-actions">
														<button
																className="wptd-row-btn"
																onClick={ () => onViewDetails( item ) }
																title={ __( 'View details', 'wptd' ) }
																aria-label={ __( 'View details', 'wptd' ) }
														>
																<Icon name="info" size={ 14 } />
														</button>
														<button
																className={ `wptd-row-btn wptd-row-btn--primary ${ isBusy ? 'is-busy' : '' }` }
																onClick={ () => handleDownload( item ) }
																disabled={ isBusy }
																title={ __( 'Download ZIP', 'wptd' ) }
																aria-label={ __( 'Download ZIP', 'wptd' ) }
														>
																{ isBusy ? (
																		<span className="wptd-btn-spinner" />
																) : (
																		<Icon name="download" size={ 14 } strokeWidth={ 2.4 } />
																) }
																<span>{ isBusy ? __( 'Zipping', 'wptd' ) : __( 'ZIP', 'wptd' ) }</span>
														</button>
												</td>
										</tr>
								);
						} ) }
				</tbody>
			</table>
		</div>
	);
}
