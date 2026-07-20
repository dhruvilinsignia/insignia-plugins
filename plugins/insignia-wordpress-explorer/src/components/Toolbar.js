/**
 * Toolbar.js — sort dropdown, status filter, bulk action bar.
 */
import { __ } from '@wordpress/i18n';
import Select from './Select';

export default function Toolbar( {
	sort, onSortChange,
	statusFilter, onStatusFilterChange,
	selectedCount, onBulkDownload, onClearSelection,
	total, shown,
} ) {
	const sortOptions = [
		{ value: 'name', label: __( 'Name (A-Z)', 'wptd' ) },
		{ value: 'name-desc', label: __( 'Name (Z-A)', 'wptd' ) },
		{ value: 'size', label: __( 'Largest first', 'wptd' ) },
		{ value: 'size-asc', label: __( 'Smallest first', 'wptd' ) },
		{ value: 'modified', label: __( 'Recently modified', 'wptd' ) },
		{ value: 'status', label: __( 'Active first', 'wptd' ) },
	];

	return (
		<div className="wptd-toolbar">
			<div className="wptd-toolbar__left">
				<Select
					value={ sort }
					onChange={ onSortChange }
					options={ sortOptions }
					ariaLabel={ __( 'Sort by', 'wptd' ) }
					icon="sort"
				/>

				<div className="wptd-segmented">
					<button
						className={ `wptd-seg ${ statusFilter === 'all' ? 'is-active' : '' }` }
						onClick={ () => onStatusFilterChange( 'all' ) }
					>{ __( 'All', 'wptd' ) }</button>
					<button
						className={ `wptd-seg ${ statusFilter === 'active' ? 'is-active' : '' }` }
						onClick={ () => onStatusFilterChange( 'active' ) }
					>{ __( 'Active', 'wptd' ) }</button>
					<button
						className={ `wptd-seg ${ statusFilter === 'inactive' ? 'is-active' : '' }` }
						onClick={ () => onStatusFilterChange( 'inactive' ) }
					>{ __( 'Inactive', 'wptd' ) }</button>
				</div>
			</div>

			<div className="wptd-toolbar__right">
				<span className="wptd-count-pill">
					{ shown } / { total }
				</span>

				{ selectedCount > 0 && (
					<div className="wptd-bulk-bar">
						<button
							className="wptd-btn wptd-btn--ghost"
							onClick={ onClearSelection }
							aria-label={ __( 'Clear selection', 'wptd' ) }
						>✕</button>
						<span className="wptd-bulk-count">
							{ selectedCount } { __( 'selected', 'wptd' ) }
						</span>
						<button
							className="wptd-btn wptd-btn--primary"
							onClick={ onBulkDownload }
						>
							<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
								<path d="M12 3v12" /><polyline points="6 11 12 17 18 11" /><path d="M5 21h14" />
							</svg>
							{ __( 'Download all as ZIP', 'wptd' ) }
						</button>
					</div>
				) }
			</div>
		</div>
	);
}
