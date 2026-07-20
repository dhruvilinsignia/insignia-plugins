/**
 * DetailsModal.js — slide-in side panel with file tree, size, and download.
 */
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { fetchDetails, triggerDownload } from '../api/download';
import { formatSize, formatDate, timeAgo } from './utils';
import { Icon, FileIcon, FolderIcon } from './Icon';
import { toast } from './Toast';


export default function DetailsModal( { item, type, onClose } ) {
	const [ data, setData ]     = useState( null );
	const [ error, setError ]   = useState( null );
	const [ loading, setLoading ] = useState( true );
	const [ downloading, setDownloading ] = useState( false );
	const [ expanded, setExpanded ] = useState( new Set() );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setData( null );
		setError( null );

		fetchDetails( type, item.slug )
			.then( ( res ) => {
				if ( cancelled ) return;
				setData( res );
				setLoading( false );
			} )
			.catch( ( err ) => {
				if ( cancelled ) return;
				setError( err.message || __( 'Failed to load details.', 'wptd' ) );
				setLoading( false );
			} );

		return () => { cancelled = true; };
	}, [ item.slug, type ] );

	// ESC to close.
	useEffect( () => {
		const h = ( e ) => { if ( e.key === 'Escape' ) onClose(); };
		window.addEventListener( 'keydown', h );
		return () => window.removeEventListener( 'keydown', h );
	}, [ onClose ] );

	const handleDownload = () => {
		setDownloading( true );
		triggerDownload( type, item.slug );
		toast( __( 'Preparing ZIP download…', 'wptd' ), 'info' );
		setTimeout( () => setDownloading( false ), 2500 );
	};

	const toggleNode = ( name ) => {
		setExpanded( ( prev ) => {
			const next = new Set( prev );
			if ( next.has( name ) ) next.delete( name );
			else next.add( name );
			return next;
		} );
	};

	return (
		<div className="wptd-modal-overlay" onClick={ onClose }>
			<aside
				className="wptd-modal"
				onClick={ ( e ) => e.stopPropagation() }
				role="dialog"
				aria-modal="true"
				aria-label={ item.name }
			>
				<div className="wptd-modal__header">
					<div className="wptd-modal__title-block">
						<span className="wptd-modal__type">{ type }</span>
						<h2 className="wptd-modal__title">{ item.name }</h2>
						<code className="wptd-modal__slug">{ item.slug }</code>
					</div>
					<button className="wptd-modal__close" onClick={ onClose } aria-label={ __( 'Close', 'wptd' ) }>✕</button>
				</div>

				<div className="wptd-modal__body">
					{ loading && (
						<div className="wptd-modal__loading">
							<div className="wptd-skeleton-line wptd-skeleton--lg" />
							<div className="wptd-skeleton-line" />
							<div className="wptd-skeleton-line" />
							<div className="wptd-skeleton-line wptd-skeleton--sm" />
						</div>
					) }

					{ error && (
						<div className="wptd-modal__error">
							<span>⚠</span>
							<p>{ error }</p>
						</div>
					) }

					{ ! loading && ! error && data && (
						<>
							{ item.description && (
								<p className="wptd-modal__desc">{ item.description }</p>
							) }

							<div className="wptd-modal__stats">
								<div className="wptd-modal__stat">
									<span className="wptd-modal__stat-label">{ __( 'Total size', 'wptd' ) }</span>
									<span className="wptd-modal__stat-value">{ data.size_human }</span>
								</div>
								<div className="wptd-modal__stat">
									<span className="wptd-modal__stat-label">{ __( 'Files', 'wptd' ) }</span>
									<span className="wptd-modal__stat-value">{ data.file_count }</span>
								</div>
								<div className="wptd-modal__stat">
									<span className="wptd-modal__stat-label">{ __( 'Version', 'wptd' ) }</span>
									<span className="wptd-modal__stat-value">{ item.version || '—' }</span>
								</div>
								<div className="wptd-modal__stat">
									<span className="wptd-modal__stat-label">{ __( 'Last modified', 'wptd' ) }</span>
									<span className="wptd-modal__stat-value" title={ formatDate( data.modified ) }>
										{ data.modified ? timeAgo( data.modified ) : '—' }
									</span>
								</div>
								<div className="wptd-modal__stat">
									<span className="wptd-modal__stat-label">{ __( 'Status', 'wptd' ) }</span>
									<span className="wptd-modal__stat-value">
										<span className={ `wptd-pill ${ item.active ? 'wptd-pill--active' : 'wptd-pill--inactive' }` }>
											<span className="wptd-pill__dot" />
											{ item.active ? __( 'Active', 'wptd' ) : __( 'Inactive', 'wptd' ) }
										</span>
									</span>
								</div>
								{ item.author && (
									<div className="wptd-modal__stat">
										<span className="wptd-modal__stat-label">{ __( 'Author', 'wptd' ) }</span>
										<span className="wptd-modal__stat-value">{ item.author }</span>
									</div>
								) }
							</div>

							<div className="wptd-modal__section">
								<h4 className="wptd-modal__section-title">
									{ __( 'File browser', 'wptd' ) }
									<span className="wptd-modal__section-count">
										{ data.tree.length } { __( 'entries at root', 'wptd' ) }
									</span>
								</h4>
								<div className="wptd-tree">
									{ data.tree.map( ( node ) => (
										<div key={ node.name } className="wptd-tree__node-wrap">
											<div
												className={ `wptd-tree__node ${ node.type === 'dir' ? 'is-dir' : '' } ${ expanded.has( node.name ) ? 'is-expanded' : '' }` }
												onClick={ () => node.type === 'dir' && toggleNode( node.name ) }
											>
												<span className="wptd-tree__icon">
													{ node.type === 'dir' ? <FolderIcon open={ expanded.has( node.name ) } size={ 14 } /> : <FileIcon ext={ node.ext } size={ 14 } /> }
												</span>
												<span className="wptd-tree__name">{ node.name }</span>
												{ node.type === 'dir' && node.count > 0 && (
													<span className="wptd-tree__count">{ node.count }</span>
												) }
												{ node.type === 'file' && node.size > 0 && (
													<span className="wptd-tree__size">{ formatSize( node.size ) }</span>
												) }
												{ node.type === 'dir' && (
													<span className="wptd-tree__caret">{ expanded.has( node.name ) ? '▾' : '▸' }</span>
												) }
											</div>
											{ node.type === 'dir' && expanded.has( node.name ) && node.children && (
												<div className="wptd-tree__children">
													{ node.children.map( ( c, i ) => (
														<div key={ c.name + i } className={ `wptd-tree__node wptd-tree__node--child ${ c.type === 'more' ? 'is-more' : '' }` }>
															<span className="wptd-tree__icon">
																{ c.type === 'more' ? '…' : c.type === 'dir' ? <FolderIcon size={ 14 } /> : <FileIcon ext={ c.ext } size={ 14 } /> }
															</span>
															<span className="wptd-tree__name">{ c.name }</span>
															{ c.size > 0 && (
																<span className="wptd-tree__size">{ formatSize( c.size ) }</span>
															) }
														</div>
													) ) }
												</div>
											) }
										</div>
									) ) }
								</div>
							</div>
						</>
					) }
				</div>

				<div className="wptd-modal__footer">
					<button className="wptd-btn wptd-btn--ghost" onClick={ onClose }>
						{ __( 'Close', 'wptd' ) }
					</button>
					<button
						className={ `wptd-btn wptd-btn--primary ${ downloading ? 'is-busy' : '' }` }
						onClick={ handleDownload }
						disabled={ downloading }
					>
						{ downloading ? (
							<>
								<span className="wptd-btn-spinner" />
								{ __( 'Preparing…', 'wptd' ) }
							</>
						) : (
							<>
								<Icon name="download" size={ 14 } strokeWidth={ 2.4 } />
								{ __( 'Download ZIP', 'wptd' ) }
								{ data && data.size_human && (
									<span className="wptd-btn-meta">· { data.size_human }</span>
								) }
							</>
						) }
					</button>
				</div>
			</aside>
		</div>
	);
}
