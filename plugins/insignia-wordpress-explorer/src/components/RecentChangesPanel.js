/**
 * RecentChangesPanel.js — slide-over panel showing every file edit made
 * in the last N hours, with a git-style diff viewer for each edit.
 *
 * Features
 *  - Time filter pills: 1h, 2h, 6h, 24h, All
 *  - List of revisions (newest first), each showing:
 *      • file path
 *      • time ago
 *      • user who made the edit
 *      • additions / deletions stats (green/red)
 *  - Click a revision → fetches the diff and shows it inline
 *    (unified diff with + / - / context lines, color-coded)
 *  - "Restore" button on each revision → reverts the file to its
 *    pre-edit state (with a confirm dialog)
 *  - "Clear history" button in the footer
 *
 * The diff is computed server-side by the /diff endpoint (LCS-based
 * line diff) and returned as both raw text and structured hunks. We
 * render the structured hunks so we can color-code each line.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect, useCallback } from '@wordpress/element';
import { fetchRevisions, fetchDiff, restoreRevision, clearRevisions } from '../api/download';
import { timeAgo, formatSize } from './utils';
import { Icon } from './Icon';
import { toast } from './Toast';
import { confirmDialog } from './ConfirmDialog';

const TIME_FILTERS = [
	{ hours: 1,  label: __( '1 hour', 'wptd' ) },
	{ hours: 2,  label: __( '2 hours', 'wptd' ) },
	{ hours: 6,  label: __( '6 hours', 'wptd' ) },
	{ hours: 24, label: __( '24 hours', 'wptd' ) },
	{ hours: 0,  label: __( 'All time', 'wptd' ) },
];

export default function RecentChangesPanel( { open, onClose } ) {
	const [ hours, setHours ]           = useState( 1 );
	const [ revisions, setRevisions ]   = useState( [] );
	const [ loading, setLoading ]       = useState( false );
	const [ selectedId, setSelectedId ] = useState( null );
	const [ diff, setDiff ]             = useState( null );
	const [ diffLoading, setDiffLoading ] = useState( false );
	const [ restoring, setRestoring ]  = useState( null );
	const [ clearing, setClearing ]    = useState( false );

	// Load revisions whenever the panel opens or the time filter changes.
	const load = useCallback( async () => {
		setLoading( true );
		try {
			const res = await fetchRevisions( hours );
			setRevisions( res.revisions || [] );
		} catch ( err ) {
			toast( err.message || __( 'Failed to load revisions.', 'wptd' ), 'error' );
		} finally {
			setLoading( false );
		}
	}, [ hours ] );

	useEffect( () => {
		if ( open ) load();
	}, [ open, load ] );

	// ESC to close.
	useEffect( () => {
		if ( ! open ) return;
		const h = ( e ) => { if ( e.key === 'Escape' ) onClose(); };
		window.addEventListener( 'keydown', h );
		return () => window.removeEventListener( 'keydown', h );
	}, [ open, onClose ] );

	// Fetch the diff when a revision is selected.
	useEffect( () => {
		if ( ! selectedId ) {
			setDiff( null );
			return;
		}
		setDiffLoading( true );
		setDiff( null );
		fetchDiff( selectedId )
			.then( ( res ) => { setDiff( res ); setDiffLoading( false ); } )
			.catch( ( err ) => {
				toast( err.message || __( 'Failed to load diff.', 'wptd' ), 'error' );
				setDiffLoading( false );
			} );
	}, [ selectedId ] );

	if ( ! open ) return null;

	const handleRestore = async ( rev ) => {
		const ok = await confirmDialog( {
			title: __( 'Restore this file?', 'wptd' ),
			message: (
				<>
					<p>{ __( 'This will overwrite the current content of:', 'wptd' ) }</p>
					<code className="wptd-confirm__code">{ rev.path }</code>
					<p>{ __( 'with its content from before this edit. Any changes you made AFTER this edit will be lost.', 'wptd' ) }</p>
				</>
			),
			confirmLabel: __( 'Restore', 'wptd' ),
			cancelLabel: __( 'Cancel', 'wptd' ),
			variant: 'warning',
			icon: 'refresh',
		} );
		if ( ! ok ) return;

		setRestoring( rev.id );
		try {
			await restoreRevision( rev.id );
			toast( __( 'File restored.', 'wptd' ), 'success' );
			// Reload the list.
			load();
		} catch ( err ) {
			toast( err.message || __( 'Failed to restore.', 'wptd' ), 'error' );
		} finally {
			setRestoring( null );
		}
	};

	const handleClear = async () => {
		const ok = await confirmDialog( {
			title: __( 'Clear all revision history?', 'wptd' ),
			message: __( 'Every recorded file edit will be permanently removed. The files themselves are NOT affected — only the change history. This cannot be undone.', 'wptd' ),
			confirmLabel: __( 'Clear all', 'wptd' ),
			cancelLabel: __( 'Keep history', 'wptd' ),
			variant: 'danger',
			icon: 'trash',
		} );
		if ( ! ok ) return;

		setClearing( true );
		try {
			await clearRevisions();
			setRevisions( [] );
			setSelectedId( null );
			setDiff( null );
			toast( __( 'Revision history cleared.', 'wptd' ), 'success' );
		} catch ( err ) {
			toast( err.message || __( 'Failed to clear history.', 'wptd' ), 'error' );
		} finally {
			setClearing( false );
		}
	};

	return (
		<div className="wptd-modal-overlay" onClick={ onClose }>
			<aside
				className="wptd-modal wptd-modal--revisions"
				onClick={ ( e ) => e.stopPropagation() }
				role="dialog"
				aria-modal="true"
				aria-label={ __( 'Recent changes', 'wptd' ) }
			>
				{/* Header */}
				<div className="wptd-modal__header">
					<div className="wptd-modal__title-block">
						<h2 className="wptd-modal__title">{ __( 'Recent changes', 'wptd' ) }</h2>
						<span className="wptd-modal__sub">
							{ revisions.length > 0
								? `${ revisions.length } ${ __( 'edit(s) in the selected period', 'wptd' ) }`
								: __( 'No edits in the selected period' )
							}
						</span>
					</div>
					<button className="wptd-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'wptd' ) }>✕</button>
				</div>

				{/* Time filter pills */}
				<div className="wptd-revisions-filters">
					{ TIME_FILTERS.map( ( f ) => (
						<button
							key={ f.hours }
							className={ `wptd-revisions-filter ${ hours === f.hours ? 'is-active' : '' }` }
							onClick={ () => { setHours( f.hours ); setSelectedId( null ); setDiff( null ); } }
						>
							{ f.label }
						</button>
					) ) }
					<button
						className="wptd-revisions-refresh"
						onClick={ load }
						title={ __( 'Refresh', 'wptd' ) }
					>
						<Icon name="refresh" size={ 14 } />
					</button>
				</div>

				{/* Body — split layout: list on top (or left), diff below (or right) */}
				<div className="wptd-revisions-body">
					{/* Revision list */}
					<div className="wptd-revisions-list">
						{ loading && (
							<div className="wptd-history-loading">
								<div className="wptd-skeleton-line" />
								<div className="wptd-skeleton-line" />
								<div className="wptd-skeleton-line wptd-skeleton--sm" />
							</div>
						) }

						{ ! loading && revisions.length === 0 && (
							<div className="wptd-empty wptd-empty--inline">
								<div className="wptd-empty__icon"><Icon name="clock" size={ 48 } /></div>
								<h3>{ __( 'No changes yet', 'wptd' ) }</h3>
								<p>{ __( 'Edits you make in the code editor will appear here with a git-style diff.', 'wptd' ) }</p>
							</div>
						) }

						{ ! loading && revisions.length > 0 && (
							<ul className="wptd-revision-items">
								{ revisions.map( ( rev ) => {
									const isActive = rev.id === selectedId;
									const sizeDelta = rev.size - rev.old_size;
									return (
										<li
											key={ rev.id }
											className={ `wptd-revision-item ${ isActive ? 'is-active' : '' }` }
											onClick={ () => setSelectedId( isActive ? null : rev.id ) }
										>
											<div className="wptd-revision-item__icon">
												<Icon name="file-text" size={ 16 } />
											</div>
											<div className="wptd-revision-item__body">
												<span className="wptd-revision-item__path" title={ rev.path }>
													{ rev.path }
												</span>
												<span className="wptd-revision-item__meta">
													<span className="wptd-revision-item__time">{ timeAgo( rev.timestamp ) }</span>
													{ rev.user_name && (
														<>
															<span className="wptd-revision-item__dot">·</span>
															<span>{ rev.user_name }</span>
														</>
													) }
													<span className="wptd-revision-item__dot">·</span>
													<span>{ formatSize( rev.size ) }</span>
													{ sizeDelta !== 0 && (
														<span className={ `wptd-revision-item__delta ${ sizeDelta > 0 ? 'is-up' : 'is-down' }` }>
															({ sizeDelta > 0 ? '+' : '' }{ formatSize( Math.abs( sizeDelta ) ) })
														</span>
													) }
												</span>
											</div>
											{ rev.has_diff && (
												<button
													className="wptd-revision-item__restore"
													onClick={ ( e ) => { e.stopPropagation(); handleRestore( rev ); } }
													disabled={ restoring === rev.id }
													title={ __( 'Restore to before this edit', 'wptd' ) }
												>
													{ restoring === rev.id
														? <span className="wptd-btn-spinner wptd-btn-spinner--xs" />
														: <Icon name="refresh" size={ 14 } />
													}
												</button>
											) }
										</li>
									);
								} ) }
							</ul>
						) }
					</div>

					{/* Diff viewer */}
					<div className="wptd-diff-viewer">
						{ ! selectedId && (
							<div className="wptd-empty wptd-empty--inline">
								<div className="wptd-empty__icon"><Icon name="code" size={ 48 } /></div>
								<h3>{ __( 'Select an edit', 'wptd' ) }</h3>
								<p>{ __( 'Click any edit on the left to see a git-style diff of what changed.', 'wptd' ) }</p>
							</div>
						) }

						{ selectedId && diffLoading && (
							<div className="wptd-history-loading">
								<div className="wptd-skeleton-line" />
								<div className="wptd-skeleton-line" />
								<div className="wptd-skeleton-line" />
								<div className="wptd-skeleton-line wptd-skeleton--sm" />
							</div>
						) }

						{ selectedId && ! diffLoading && diff && (
							<DiffView diff={ diff } />
						) }
					</div>
				</div>

				{/* Footer */}
				{ revisions.length > 0 && (
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

/**
 * DiffView — renders a git-style unified diff with color-coded lines.
 *
 * Props:
 *   diff = {
 *     id, path, timestamp,
 *     diff: string,         // raw unified diff text
 *     hunks: Array,         // structured hunks
 *     stats: { additions, deletions }
 *   }
 */
function DiffView( { diff } ) {
	const hunks = diff.hunks || [];
	const stats = diff.stats || { additions: 0, deletions: 0 };

	return (
		<div className="wptd-diff">
			{/* Diff header — path + stats */}
			<div className="wptd-diff__header">
				<span className="wptd-diff__path" title={ diff.path }>{ diff.path }</span>
				<span className="wptd-diff__stats">
					<span className="wptd-diff__stat wptd-diff__stat--add">+{ stats.additions }</span>
					<span className="wptd-diff__stat wptd-diff__stat--del">-{ stats.deletions }</span>
				</span>
			</div>

			{/* Diff body — line-by-line */}
			<div className="wptd-diff__body">
				{ hunks.length === 0 ? (
					<div className="wptd-diff__empty">
						{ __( 'No line-level changes recorded for this edit (the file may have been too large).', 'wptd' ) }
					</div>
				) : (
					hunks.map( ( hunk, hi ) => (
						<div key={ hi } className="wptd-diff__hunk">
							<div className="wptd-diff__hunk-header">
								@@ -{ hunk.old_start } +{ hunk.new_start } @@
							</div>
							{ hunk.lines.map( ( line, li ) => {
								const cls = `wptd-diff__line wptd-diff__line--${ line.type }`;
								const prefix = line.type === 'add' ? '+' : line.type === 'del' ? '-' : ' ';
								return (
									<div key={ li } className={ cls }>
										<span className="wptd-diff__line-num">
											{ line.type === 'context' && <span className="wptd-diff__line-old">{ line.old_line }</span> }
											{ line.type === 'context' && <span className="wptd-diff__line-new">{ line.new_line }</span> }
											{ line.type === 'del' && <span className="wptd-diff__line-old">{ line.old_line }</span> }
											{ line.type === 'add' && <span className="wptd-diff__line-new">{ line.new_line }</span> }
										</span>
										<span className="wptd-diff__line-prefix">{ prefix }</span>
										<span className="wptd-diff__line-text">{ line.text }</span>
									</div>
								);
							} ) }
						</div>
					) )
				) }
			</div>
		</div>
	);
}
