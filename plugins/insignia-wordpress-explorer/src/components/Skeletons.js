/**
 * Skeletons.js — shimmer-loading placeholders for the grid & stats.
 */
export default function Skeletons( { view = 'grid' } ) {
	if ( view === 'table' ) {
		return (
			<div className="wptd-table-wrap">
				<table className="wptd-table">
					<thead>
						<tr>
							{ [ '', 'Name', 'Slug', 'Version', 'Size', 'Status', 'Modified', 'Actions' ].map( ( h, i ) => (
								<th key={ i }>{ h }</th>
							) ) }
						</tr>
					</thead>
					<tbody>
						{ Array.from( { length: 8 } ).map( ( _, i ) => (
							<tr key={ i }>
								{ Array.from( { length: 8 } ).map( ( __, j ) => (
									<td key={ j }>
										<div className="wptd-skeleton-line wptd-skeleton--sm" />
									</td>
								) ) }
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
		);
	}

	return (
		<div className="wptd-grid">
			{ Array.from( { length: 6 } ).map( ( _, i ) => (
				<div className="wptd-card wptd-card--skeleton" key={ i }>
					<div className="wptd-card__top">
						<div className="wptd-skeleton-box wptd-skeleton--avatar" />
						<div className="wptd-card__header">
							<div className="wptd-skeleton-line wptd-skeleton--lg" />
							<div className="wptd-skeleton-line wptd-skeleton--sm" />
						</div>
					</div>
					<div className="wptd-skeleton-line" />
					<div className="wptd-skeleton-line wptd-skeleton--sm" />
					<div className="wptd-card__meta">
						<div className="wptd-skeleton-box wptd-skeleton--chip" />
						<div className="wptd-skeleton-box wptd-skeleton--chip" />
						<div className="wptd-skeleton-box wptd-skeleton--chip" />
					</div>
					<div className="wptd-skeleton-box wptd-skeleton--btn" />
				</div>
			) ) }
		</div>
	);
}
